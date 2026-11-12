<?php

use App\Models\Media;
use App\Support\Media\DirectServing;
use App\Support\Settings\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
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

it('is due for a check unless the link is missing, a fresh probe succeeded or one was just tried', function () {
    linkPublicDiskInTempDir(link: false);
    expect(freshDirectServing()->dueForCheck())->toBeFalse();

    linkPublicDiskInTempDir();
    $service = freshDirectServing();
    expect($service->dueForCheck())->toBeTrue();

    $service->record(false, 'https://example.test/storage');
    expect($service->dueForCheck())->toBeFalse();

    $this->travel(31)->minutes();
    expect($service->dueForCheck())->toBeTrue();

    $service->record(true, 'https://example.test/storage');
    expect($service->dueForCheck())->toBeFalse();

    $this->travel(23)->hours();
    expect($service->dueForCheck())->toBeFalse();

    $this->travel(2)->hours();
    expect($service->dueForCheck())->toBeTrue();
});

it('backs off for 30 minutes after an attempt whose report never came, unless the url changes', function () {
    linkPublicDiskInTempDir();
    $service = freshDirectServing();

    $service->markAttempt();
    expect($service->state())->toBe('unchecked')
        ->and($service->dueForCheck())->toBeFalse();

    $this->travel(29)->minutes();
    expect($service->dueForCheck())->toBeFalse();

    config(['filesystems.disks.public.url' => 'https://www.example.test/storage']);
    expect($service->dueForCheck())->toBeTrue();

    config(['filesystems.disks.public.url' => 'https://example.test/storage']);
    $this->travel(2)->minutes();
    expect($service->dueForCheck())->toBeTrue();
});

it('also backs off a due recheck of a direct state whose report never came', function () {
    linkPublicDiskInTempDir();
    $service = freshDirectServing();
    $service->record(true, 'https://example.test/storage');

    $this->travel(25)->hours();
    $service->markAttempt();

    expect($service->state())->toBe('direct')
        ->and($service->dueForCheck())->toBeFalse();
});

it('skips the write when the same result for the same url comes again within 30 minutes', function () {
    linkPublicDiskInTempDir();
    $service = freshDirectServing();
    $this->freezeSecond();
    $service->record(false, 'https://example.test/storage');
    $first = app(SiteSettings::class)->value('media_direct_checked_at');

    $this->travel(10)->minutes();
    DB::enableQueryLog();
    $service->record(false, 'https://example.test/storage');
    $writes = collect(DB::getQueryLog())->filter(fn (array $query) => ! str_starts_with(strtolower($query['query']), 'select'))->count();
    DB::disableQueryLog();

    expect($writes)->toBe(0)
        ->and(app(SiteSettings::class)->value('media_direct_checked_at'))->toBe($first);

    $service->record(false, 'https://example.test/storage', force: true);
    expect(app(SiteSettings::class)->value('media_direct_checked_at'))->not->toBe($first);

    $this->travel(31)->minutes();
    $forced = app(SiteSettings::class)->value('media_direct_checked_at');
    $service->record(false, 'https://example.test/storage');
    expect(app(SiteSettings::class)->value('media_direct_checked_at'))->not->toBe($forced);
});

it('records without re-applying the settings, so logos, mailers and runtime config stay', function () {
    linkPublicDiskInTempDir();
    app(SiteSettings::class)->put(['site_name' => 'Stored name']);
    config(['app.name' => 'Changed at runtime']);
    Mail::spy();

    freshDirectServing()->record(true, 'https://example.test/storage');
    freshDirectServing()->markAttempt();

    expect(config('app.name'))->toBe('Changed at runtime')
        ->and(freshDirectServing()->state())->toBe('direct');
    Mail::shouldNotHaveReceived('forgetMailers');
});

it('builds the probe url and keeps one probe image of a random size', function () {
    Storage::fake('public');
    config(['filesystems.disks.public.url' => 'https://example.test/storage/']);
    Storage::disk('public')->put('media/.probe', 'old token');

    $size = freshDirectServing()->probeSize();
    $bytes = (string) Storage::disk('public')->get('media/probe.png');
    $info = getimagesizefromstring($bytes);

    expect(freshDirectServing()->probeUrl())->toBe('https://example.test/storage/media/probe.png')
        ->and($size['width'])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(64)
        ->and($size['height'])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(64)
        ->and($info[0] ?? null)->toBe($size['width'])
        ->and($info[1] ?? null)->toBe($size['height'])
        ->and($info['mime'] ?? null)->toBe('image/png')
        ->and(imagecreatefromstring($bytes))->toBeInstanceOf(GdImage::class)
        ->and(freshDirectServing()->probeSize())->toBe($size);
    Storage::disk('public')->assertMissing('media/.probe');
});

it('writes a new probe image when the stored one is not a valid probe', function () {
    Storage::fake('public');
    Storage::disk('public')->put('media/probe.png', 'not an image');

    $size = freshDirectServing()->probeSize();

    expect(getimagesizefromstring((string) Storage::disk('public')->get('media/probe.png'))[0] ?? null)->toBe($size['width']);
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

it('builds the urls of many public rasters without a query per url', function (string $state) {
    linkPublicDiskInTempDir(link: $state !== 'link_missing');
    config(['media.disk' => 'public', 'cache.default' => 'database']);
    Storage::fake('local');

    if ($state !== 'link_missing' && $state !== 'unchecked') {
        freshDirectServing()->record($state === 'direct', 'https://example.test/storage');
    }

    $rows = Media::factory()->count(20)->create(['disk' => 'public', 'thumb_path' => 'media/thumbs/t-v1.webp']);

    /** Queries made while building the urls of $count rows, as a fresh request does after reading the settings at boot. */
    $queriesFor = function (int $count) use ($rows): int {
        app(SiteSettings::class)->apply();
        freshDirectServing();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $rows->take($count)->each(fn (Media $media) => [$media->url(), $media->thumbUrl()]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($queriesFor(20))->toBe($queriesFor(1))
        ->and($queriesFor(20))->toBe(0)
        ->and(freshDirectServing()->state())->toBe($state);
})->with(['direct', 'unchecked', 'unreachable', 'link_missing']);

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

it('forgets the link check before every queued job, so a long-lived worker sees a removed link', function () {
    $base = linkPublicDiskInTempDir();
    $service = freshDirectServing();
    expect($service->linked())->toBeTrue();

    unlink($base.'/link');
    expect($service->linked())->toBeTrue();

    event(new JobProcessing('database', new SyncJob(app(), '{}', 'database', 'default')));

    expect($service->linked())->toBeFalse();
});
