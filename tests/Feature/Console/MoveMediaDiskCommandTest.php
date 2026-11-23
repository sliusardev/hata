<?php

use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Models\Media;
use Hata\Support\Media\DiskMoveRunStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    fakeCloudDisk('s3');
    config(['filesystems.disks.s3.bucket' => 'media']);
});

function moveCommandCandidate(string $disk = 'local'): Media
{
    $media = Media::factory()->create(['disk' => $disk, 'size' => 4]);
    Storage::disk($disk)->put($media->path, 'data');

    return $media;
}

it('moves every file to the given disk and reports progress', function () {
    foreach (range(1, 3) as $_) {
        moveCommandCandidate();
    }

    $this->artisan('media:move-disk s3')
        ->expectsOutputToContain('3 file(s) to move')
        ->expectsOutputToContain('3/3')
        ->expectsOutputToContain('Done.')
        ->assertSuccessful();

    expect(Media::query()->where('disk', 's3')->count())->toBe(3);
});

it('moves files back to this server', function () {
    $media = moveCommandCandidate('s3');

    $this->artisan('media:move-disk public')
        ->expectsOutputToContain('Starting a new move to This server (public): 1 file(s) to move.')
        ->assertSuccessful();

    expect($media->fresh()->disk)->toBe('public');
    Storage::disk('public')->assertExists($media->fresh()->path);
});

it('refuses an unknown or unconfigured disk', function () {
    $this->artisan('media:move-disk ftp')->expectsOutputToContain('Unknown disk')->assertFailed();
    $this->artisan('media:move-disk local')->expectsOutputToContain('Unknown disk [local]; use one of: public, s3.')->assertFailed();

    config(['filesystems.disks.s3.bucket' => null]);
    $this->artisan('media:move-disk s3')->expectsOutputToContain('not configured')->assertFailed();

    expect(app(DiskMoveRunStore::class)->get())->toBeNull();
});

it('resumes a paused move to the same disk and starts over for another one', function () {
    moveCommandCandidate();
    moveCommandCandidate();
    $engine = app(MoveMediaToDisk::class);
    $paused = $engine->startWithoutActor('s3');
    $engine->pause();

    $this->artisan('media:move-disk s3')->expectsOutputToContain('Resuming')->assertSuccessful();
    expect(app(DiskMoveRunStore::class)->get()->id)->toBe($paused->id);

    $this->artisan('media:move-disk public')->expectsOutputToContain('Starting a new move')->assertSuccessful();
    expect(app(DiskMoveRunStore::class)->get()->id)->not->toBe($paused->id)
        ->and(Media::query()->where('disk', 'public')->count())->toBe(2);
});

it('gives up when another batch keeps the lock', function () {
    Sleep::fake();
    moveCommandCandidate();
    $lock = Cache::lock(MoveMediaToDisk::LOCK, 60);
    $lock->get();

    $this->artisan('media:move-disk s3')
        ->expectsOutputToContain('Another batch is running; try again later.')
        ->assertFailed();

    $lock->release();
});
