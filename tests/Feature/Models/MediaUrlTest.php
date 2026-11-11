<?php

use App\Enums\MediaKind;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('builds app-route urls for local files and keeps appUrl free of the version', function () {
    $media = Media::factory()->create(['thumb_path' => 'media/thumbs/x.webp', 'version' => 3]);

    expect($media->url())->toBe(route('media.show', [$media->key, $media->name, 'v' => 3]))
        ->and($media->thumbUrl())->toBe(route('media.show', [$media->key, $media->name, 'thumb' => 1, 'v' => 3]))
        ->and($media->appUrl())->toBe(route('media.show', [$media->key, $media->name]));
});

it('uses direct urls for cloud rasters with a public url and the app route otherwise', function () {
    config(['filesystems.disks.s3.driver' => 's3', 'filesystems.disks.s3.url' => 'https://cdn.example.test']);
    Storage::fake('s3', ['url' => 'https://cdn.example.test']);

    $image = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/A-v2.webp', 'thumb_path' => 'media/thumbs/A-v2.webp']);
    $svg = Media::factory()->create(['disk' => 's3', 'name' => 'l.svg', 'mime' => 'image/svg+xml', 'path' => 'media/2026/11/B-v1.svg']);
    $pdf = Media::factory()->document()->create(['disk' => 's3']);
    $local = Media::factory()->create();

    expect($image->url())->toBe(Storage::disk('s3')->url('media/2026/11/A-v2.webp').'?v=1')
        ->and($image->url())->toStartWith('https://cdn.example.test/')
        ->and($image->thumbUrl())->toBe(Storage::disk('s3')->url('media/thumbs/A-v2.webp').'?v=1')
        ->and($svg->url())->toBe(route('media.show', [$svg->key, $svg->name, 'v' => 1]))
        ->and($pdf->url())->toBe(route('media.show', [$pdf->key, $pdf->name, 'v' => 1]))
        ->and($local->url())->toBe(route('media.show', [$local->key, $local->name, 'v' => 1]))
        ->and($image->appUrl())->toBe(route('media.show', [$image->key, $image->name]));
});

it('includes the disk prefix in the direct url', function () {
    config([
        'filesystems.disks.pfx' => [
            'driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'us-east-1', 'bucket' => 'b',
            'root' => 'site', 'url' => 'https://cdn.example.test/',
        ],
    ]);
    $media = Media::factory()->create(['disk' => 'pfx', 'path' => 'media/2026/11/A-v1.webp']);

    expect($media->url())->toBe('https://cdn.example.test/site/media/2026/11/A-v1.webp?v=1');
});

it('falls back to the app route without a public url', function () {
    config(['filesystems.disks.s3.driver' => 's3', 'filesystems.disks.s3.url' => null]);
    $media = Media::factory()->create(['disk' => 's3', 'kind' => MediaKind::Image]);

    expect($media->url())->toBe(route('media.show', [$media->key, $media->name, 'v' => 1]));
});

it('uses a direct thumbnail url for a cloud svg because the thumbnail is webp', function () {
    config(['filesystems.disks.s3.driver' => 's3', 'filesystems.disks.s3.url' => 'https://cdn.example.test']);
    Storage::fake('s3', ['url' => 'https://cdn.example.test']);
    $svg = Media::factory()->create(['disk' => 's3', 'name' => 'l.svg', 'mime' => 'image/svg+xml', 'path' => 'media/2026/11/B-v1.svg', 'thumb_path' => 'media/thumbs/B-v1.webp', 'version' => 4]);

    expect($svg->thumbUrl())->toBe(Storage::disk('s3')->url('media/thumbs/B-v1.webp').'?v=4');
});
