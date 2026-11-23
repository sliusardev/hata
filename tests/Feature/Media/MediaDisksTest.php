<?php

use Carbon\CarbonImmutable;
use Hata\Enums\MediaKind;
use Hata\Models\Media;
use Hata\Support\Media\MediaDisks;
use Hata\Support\Media\MediaPaths;

beforeEach(function () {
    config(['filesystems.disks.s3.driver' => 's3']);
});

it('tells what each disk can do', function (string $disk, bool $cloud, bool $publishes, bool $versions, string $originals) {
    expect(MediaDisks::isCloud($disk))->toBe($cloud)
        ->and(MediaDisks::publishes($disk))->toBe($publishes)
        ->and(MediaDisks::versionsPaths($disk))->toBe($versions)
        ->and(MediaDisks::originalsDisk($disk))->toBe($originals);
})->with([
    'public' => ['public', false, true, true, 'local'],
    'local' => ['local', false, false, false, 'local'],
    's3' => ['s3', true, false, true, 's3'],
]);

it('places files by site disk, kind and type', function (string $siteDisk, MediaKind $kind, string $mime, string $expected) {
    expect(MediaDisks::diskFor($siteDisk, $kind, $mime))->toBe($expected);
})->with([
    'public webp' => ['public', MediaKind::Image, 'image/webp', 'public'],
    'public svg' => ['public', MediaKind::Image, 'image/svg+xml', 'local'],
    'public pdf' => ['public', MediaKind::Document, 'application/pdf', 'local'],
    'local webp' => ['local', MediaKind::Image, 'image/webp', 'local'],
    'local svg' => ['local', MediaKind::Image, 'image/svg+xml', 'local'],
    'local pdf' => ['local', MediaKind::Document, 'application/pdf', 'local'],
    's3 webp' => ['s3', MediaKind::Image, 'image/webp', 's3'],
    's3 svg' => ['s3', MediaKind::Image, 'image/svg+xml', 's3'],
    's3 pdf' => ['s3', MediaKind::Document, 'application/pdf', 's3'],
]);

it('puts the version into the paths of a public disk but not of a private one', function () {
    $at = CarbonImmutable::parse('2026-03-05 10:00');

    expect(MediaPaths::file('public', 'KEY', 3, 'webp', $at))->toBe('media/2026/03/KEY-v3.webp')
        ->and(MediaPaths::thumb('public', 'KEY', 3))->toBe('media/thumbs/KEY-v3.webp')
        ->and(MediaPaths::original('public', 'KEY', 3, 'jpg'))->toBe('media/originals/KEY-v3.jpg')
        ->and(MediaPaths::file('local', 'KEY', 3, 'webp', $at))->not->toContain('-v3')
        ->and(MediaPaths::thumb('local', 'KEY', 3))->not->toContain('-v3')
        ->and(MediaPaths::original('local', 'KEY', 3, 'jpg'))->not->toContain('-v3')
        ->and(MediaPaths::writeOptions('public', 'image/webp'))->toBe([]);
});

it('keeps originals of a published file on the private disk', function () {
    expect(Media::factory()->make(['disk' => 'public'])->originalsDisk())->toBe('local')
        ->and(Media::factory()->make(['disk' => 's3'])->originalsDisk())->toBe('s3');
});

it('opts a test into the public media disk', function () {
    usePublicMediaDisk();

    expect(config('hata.media.disk'))->toBe('public');
});

it('treats the public disk as publishing by its name even without a url, and no other local disk', function () {
    config([
        'filesystems.disks.public.url' => '',
        'filesystems.disks.assets' => ['driver' => 'local', 'root' => storage_path('app/assets'), 'url' => 'https://example.test/assets'],
    ]);

    expect(MediaDisks::publishes('public'))->toBeTrue()
        ->and(MediaDisks::originalsDisk('public'))->toBe('local')
        ->and(MediaDisks::diskFor('public', MediaKind::Image, 'image/svg+xml'))->toBe('local')
        ->and(MediaDisks::diskFor('public', MediaKind::Document, 'application/pdf'))->toBe('local')
        ->and(MediaDisks::publishes('assets'))->toBeFalse()
        ->and(MediaDisks::publishes('local'))->toBeFalse();

    config(['filesystems.disks.public.driver' => 's3']);
    expect(MediaDisks::publishes('public'))->toBeFalse();
});

it('hands out no static url and asks no probe while the public disk has no url', function () {
    usePublicMediaDisk(direct: true);
    config(['filesystems.disks.public.url' => null]);
    $media = Media::factory()->create(['disk' => 'public']);

    expect($media->url())->toBe(route('media.show', [$media->key, $media->name, 'v' => 1]));
});
