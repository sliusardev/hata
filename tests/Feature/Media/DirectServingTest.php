<?php

use App\Models\Media;
use App\Support\Media\DirectServing;
use App\Support\Settings\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Point the real service at a temp directory: the `public` disk's root, and optionally a symlink to it.
 */
function linkPublicDiskInTempDir(bool $link = true, ?string $target = null): string
{
    $base = sys_get_temp_dir().'/direct-serving-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($base.'/root');

    config([
        'filesystems.disks.public.root' => $base.'/root',
        'filesystems.disks.public.url' => 'https://example.test/storage',
        'filesystems.links' => [$base.'/link' => $base.'/root'],
    ]);

    if ($link) {
        symlink($target ?? $base.'/root', $base.'/link');
    }

    return $base;
}

function freshDirectServing(): DirectServing
{
    app()->forgetInstance(DirectServing::class);

    return app(DirectServing::class);
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/direct-serving-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

it('reports link_missing when the link is absent or points elsewhere', function () {
    linkPublicDiskInTempDir(link: false);
    expect(freshDirectServing()->state())->toBe('link_missing');

    linkPublicDiskInTempDir(target: sys_get_temp_dir());
    expect(freshDirectServing()->state())->toBe('link_missing');
});

it('walks through unchecked, direct, unreachable and a changed url', function () {
    linkPublicDiskInTempDir();
    $service = freshDirectServing();

    expect($service->linked())->toBeTrue()
        ->and($service->state())->toBe('unchecked')
        ->and($service->active('public'))->toBeFalse();

    $service->record(true, 'https://example.test/storage');
    expect($service->state())->toBe('direct')
        ->and($service->active('public'))->toBeTrue()
        ->and($service->active('local'))->toBeFalse();

    config(['filesystems.disks.public.url' => 'https://cdn.example.test/storage']);
    expect($service->state())->toBe('unchecked');

    $service->record(false, 'https://cdn.example.test/storage');
    expect($service->state())->toBe('unreachable')
        ->and($service->active('public'))->toBeFalse();
});

it('stores the probe result in UTC', function () {
    linkPublicDiskInTempDir();

    freshDirectServing()->record(true, 'https://example.test/storage');

    $settings = app(SiteSettings::class);
    expect($settings->value('media_direct_state'))->toBe('ok')
        ->and($settings->value('media_direct_url'))->toBe('https://example.test/storage')
        ->and(Carbon::parse($settings->value('media_direct_checked_at'))->utcOffset())->toBe(0);
});

it('is due for a check unless the link is missing or a fresh probe succeeded', function () {
    linkPublicDiskInTempDir(link: false);
    expect(freshDirectServing()->dueForCheck())->toBeFalse();

    linkPublicDiskInTempDir();
    $service = freshDirectServing();
    expect($service->dueForCheck())->toBeTrue();

    $service->record(false, 'https://example.test/storage');
    expect($service->dueForCheck())->toBeTrue();

    $service->record(true, 'https://example.test/storage');
    expect($service->dueForCheck())->toBeFalse();

    $this->travel(25)->hours();
    expect($service->dueForCheck())->toBeTrue();
});

it('builds the probe url and keeps one probe token', function () {
    Storage::fake('public');
    config(['filesystems.disks.public.url' => 'https://example.test/storage/']);
    $service = freshDirectServing();

    $token = $service->probeToken();

    expect($service->probeUrl())->toBe('https://example.test/storage/media/.probe')
        ->and($token)->not->toBe('')
        ->and($service->probeToken())->toBe($token)
        ->and(Storage::disk('public')->get('media/.probe'))->toBe($token);
});

it('fakes the service with fixed answers', function () {
    DirectServing::fake(linked: false);
    expect(app(DirectServing::class)->state())->toBe('link_missing');

    DirectServing::fake(confirmed: false);
    expect(app(DirectServing::class)->state())->toBe('unchecked');

    DirectServing::fake();
    expect(app(DirectServing::class)->state())->toBe('direct');
});

it('hands out static urls for public rasters only while direct serving is active', function () {
    usePublicMediaDisk();
    $media = Media::factory()->create([
        'disk' => 'public', 'path' => 'media/2026/11/K-v1.webp', 'thumb_path' => 'media/thumbs/K-v1.webp',
    ]);
    $app = route('media.show', [$media->key, $media->name, 'v' => 1]);
    $appThumb = route('media.show', [$media->key, $media->name, 'thumb' => 1, 'v' => 1]);

    expect($media->url())->toBe($app);

    DirectServing::fake(confirmed: false);
    expect($media->url())->toBe($app);

    DirectServing::fake();
    expect($media->url())->toBe(Storage::disk('public')->url('media/2026/11/K-v1.webp').'?v=1')
        ->and($media->url())->toEndWith('/storage/media/2026/11/K-v1.webp?v=1')
        ->and($media->thumbUrl())->toBe(Storage::disk('public')->url('media/thumbs/K-v1.webp').'?v=1');

    DirectServing::fake(linked: false);
    expect($media->url())->toBe($app)->and($media->thumbUrl())->toBe($appThumb);
});

it('keeps svg and documents on the app route even when direct', function () {
    usePublicMediaDisk(direct: true);
    $svg = Media::factory()->create(['disk' => 'public', 'name' => 'l.svg', 'mime' => 'image/svg+xml', 'path' => 'media/2026/11/S-v1.svg']);
    $pdf = Media::factory()->document()->create(['disk' => 'public']);

    expect($svg->url())->toBe(route('media.show', [$svg->key, $svg->name, 'v' => 1]))
        ->and($pdf->url())->toBe(route('media.show', [$pdf->key, $pdf->name, 'v' => 1]));
});

it('leaves s3 urls unaffected by the direct state', function () {
    config(['filesystems.disks.s3.driver' => 's3', 'filesystems.disks.s3.url' => 'https://cdn.example.test']);
    Storage::fake('s3', ['url' => 'https://cdn.example.test']);
    $image = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/A-v2.webp']);
    $expected = Storage::disk('s3')->url('media/2026/11/A-v2.webp').'?v=1';

    DirectServing::fake(linked: false);
    expect($image->url())->toBe($expected);

    DirectServing::fake();
    expect($image->url())->toBe($expected);
});
