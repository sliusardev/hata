<?php

use Carbon\CarbonImmutable;
use Hata\Support\Media\MediaPaths;

it('keeps the unversioned paths on a local disk', function () {
    $at = CarbonImmutable::parse('2026-03-05 10:00');

    expect(MediaPaths::file('local', 'KEY', 3, 'webp', $at))->toBe('media/2026/03/KEY.webp')
        ->and(MediaPaths::thumb('local', 'KEY', 3))->toBe('media/thumbs/KEY.webp')
        ->and(MediaPaths::original('local', 'KEY', 3, 'jpg'))->toBe('media/originals/KEY.jpg')
        ->and(MediaPaths::writeOptions('local', 'image/webp'))->toBe([]);
});

it('puts the version into the paths on a cloud disk', function () {
    config(['filesystems.disks.s3.driver' => 's3']);
    $at = CarbonImmutable::parse('2026-03-05 10:00');

    expect(MediaPaths::file('s3', 'KEY', 3, 'webp', $at))->toBe('media/2026/03/KEY-v3.webp')
        ->and(MediaPaths::thumb('s3', 'KEY', 3))->toBe('media/thumbs/KEY-v3.webp')
        ->and(MediaPaths::original('s3', 'KEY', 3, 'jpg'))->toBe('media/originals/KEY-v3.jpg')
        ->and(MediaPaths::writeOptions('s3', 'image/webp'))->toBe([
            'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=604800',
        ]);
});

it('writes svg objects on a cloud disk as an inert download, so the bucket never renders them', function () {
    config(['filesystems.disks.s3.driver' => 's3']);

    expect(MediaPaths::writeOptions('s3', 'image/svg+xml'))->toBe([
        'ContentType' => 'application/octet-stream',
        'ContentDisposition' => 'attachment',
        'CacheControl' => 'public, max-age=604800',
    ])->and(MediaPaths::writeOptions('local', 'image/svg+xml'))->toBe([]);
});
