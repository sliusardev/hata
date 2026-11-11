<?php

use App\Actions\Media\MoveMediaToDisk;
use App\Actions\Media\RebuildMediaFile;
use App\Enums\MoveOutcome;
use App\Models\Media;
use App\Models\MediaPendingDeletion;
use App\Models\User;
use App\Support\Media\DiskMoveRunStore;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $cloud = fakeCloudDisk('s3');
    config(['filesystems.disks.s3.url' => 'https://cdn.test', 'filesystems.disks.s3.bucket' => 'media']);
    $this->cloud = $cloud;
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

/**
 * A JPEG row on the given disk with its file, thumbnail and original written there (cloud paths versioned like uploads).
 */
function diskMedia(string $disk = 'local', int $version = 1): Media
{
    $media = Media::factory()->create(['disk' => $disk, 'mime' => 'image/jpeg', 'version' => $version]);
    $suffix = $disk === 'local' ? '' : "-v{$version}";
    $media->forceFill([
        'path' => "media/2025/01/{$media->key}{$suffix}.jpg",
        'thumb_path' => "media/thumbs/{$media->key}{$suffix}.webp",
        'original_path' => "media/originals/{$media->key}{$suffix}.png",
        'name' => "{$media->key}.jpg",
        'size' => 9,
    ])->save();

    Storage::disk($disk)->put($media->path, 'file-data');
    Storage::disk($disk)->put($media->thumb_path, 'thumb');
    Storage::disk($disk)->put($media->original_path, 'original-bytes');

    return $media;
}

/**
 * Replace the faked cloud disk by one that runs $before ahead of every stream write (to fail or change things midway).
 */
function cloudDiskWriting(Closure $before): void
{
    $fake = Storage::disk('s3');

    Storage::set('s3', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $before) extends FilesystemAdapter
    {
        public function __construct($driver, $adapter, $config, private Closure $before)
        {
            parent::__construct($driver, $adapter, $config);
        }

        public function writeStream($path, $resource, array $options = [])
        {
            ($this->before)($path);

            return parent::writeStream($path, $resource, $options);
        }
    });
}

function mover(): MoveMediaToDisk
{
    return app(MoveMediaToDisk::class);
}

it('moves a local file, its thumbnail and original to the cloud disk under versioned paths', function () {
    $media = diskMedia('local');
    $old = [$media->path, $media->thumb_path, $media->original_path];

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Moved);

    $media->refresh();
    expect($media->disk)->toBe('s3')
        ->and($media->version)->toBe(2)
        ->and($media->path)->toEndWith("{$media->key}-v2.jpg")
        ->and($media->thumb_path)->toBe("media/thumbs/{$media->key}-v2.webp")
        ->and($media->original_path)->toBe("media/originals/{$media->key}-v2.png")
        ->and($media->url())->toBe(Storage::disk('s3')->url($media->path).'?v=2')->not->toContain('/media/'.$media->key.'/');

    $cloud = Storage::disk('s3');
    expect($cloud->get($media->path))->toBe('file-data')
        ->and($cloud->get($media->thumb_path))->toBe('thumb')
        ->and($cloud->get($media->original_path))->toBe('original-bytes')
        ->and($this->cloud->options[$media->path])->toBe(['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=604800'])
        ->and($this->cloud->options[$media->thumb_path]['ContentType'])->toBe('image/webp');

    // Local files go at once: nothing on this server points at them any more.
    foreach ($old as $path) {
        Storage::disk('local')->assertMissing($path);
    }
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});

it('moves a cloud file back to local paths and keeps the old objects for seven days', function () {
    $media = diskMedia('s3', version: 3);
    $old = [$media->path, $media->thumb_path, $media->original_path];

    expect(mover()->moveOne($media, 'local'))->toBe(MoveOutcome::Moved);

    $media->refresh();
    expect($media->disk)->toBe('local')
        ->and($media->version)->toBe(4)
        ->and($media->path)->toEndWith("{$media->key}.jpg")
        ->and($media->thumb_path)->toBe("media/thumbs/{$media->key}.webp")
        ->and($media->original_path)->toBe("media/originals/{$media->key}.png")
        ->and($media->url())->toContain('/media/'.$media->key.'/')->toContain('?v=4');
    expect(Storage::disk('local')->get($media->path))->toBe('file-data');

    // Pages and CDN caches rendered before still find the old objects until the deferred deletion.
    foreach ($old as $path) {
        Storage::disk('s3')->assertExists($path);
    }
    expect(MediaPendingDeletion::query()->where('disk', 's3')->pluck('path')->sort()->values()->all())->toBe(collect($old)->sort()->values()->all());
});

it('leaves the row and its old files untouched and removes partial copies when a copy fails halfway', function () {
    $media = diskMedia('local');
    $before = $media->fresh()->getAttributes();
    cloudDiskWriting(function (string $path) {
        if (str_contains($path, 'thumbs/')) {
            throw new RuntimeException('Network down');
        }
    });

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Failed);

    expect($media->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
    foreach ([$media->path, $media->thumb_path, $media->original_path] as $path) {
        Storage::disk('local')->assertExists($path);
    }
});

it('skips a file cropped while it was being copied and removes the copies', function () {
    $media = diskMedia('local');
    cloudDiskWriting(function (string $path) use ($media) {
        if (str_contains($path, 'originals/')) {
            Media::query()->whereKey($media->id)->update(['version' => 2]);
        }
    });

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Changed);

    expect($media->fresh()->disk)->toBe('local')
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
    Storage::disk('local')->assertExists($media->path);
});

it('skips a file loaded before a crop without copying anything', function () {
    $media = diskMedia('local');
    Media::query()->whereKey($media->id)->update(['version' => 2]);

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Changed)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('skips a file while a crop of it holds the row lock', function () {
    $media = diskMedia('local');
    $lock = Cache::lock(RebuildMediaFile::lockName($media), 60);
    $lock->get();

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Changed)
        ->and($media->fresh()->disk)->toBe('local');

    $lock->release();
});

it('reports a deleted row or a missing file as missing', function () {
    $gone = diskMedia('local');
    Media::query()->whereKey($gone->id)->delete();
    $noFile = diskMedia('local');
    Storage::disk('local')->delete($noFile->path);

    expect(mover()->moveOne($gone, 's3'))->toBe(MoveOutcome::Missing)
        ->and(mover()->moveOne($noFile, 's3'))->toBe(MoveOutcome::Missing)
        ->and($noFile->fresh()->disk)->toBe('local')
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('moves a file whose thumbnail is missing and forgets that thumbnail', function () {
    $media = diskMedia('local');
    Storage::disk('local')->delete($media->thumb_path);

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Moved)
        ->and($media->fresh()->thumb_path)->toBeNull()
        ->and($media->fresh()->disk)->toBe('s3');
});

it('runs every file on the other disk in batches and keeps its own run apart from optimization', function () {
    $a = diskMedia('local');
    $b = diskMedia('local');
    diskMedia('s3');

    $run = mover()->start($this->actor, 's3');

    expect($run->total)->toBe(2)
        ->and($run->target)->toBe('s3')
        ->and(app(OptimizationRunStore::class)->get())->toBeNull();

    $run = mover()->processBatch('cli', 60);

    expect($run->status)->toBe('done')
        ->and($run->counts)->toBe(['moved' => 2])
        ->and($run->bytesMoved)->toBe(18)
        ->and($run->recent[0])->toBe(['name' => $b->name, 'size' => 9, 'outcome' => 'moved'])
        ->and(app(DiskMoveRunStore::class)->get()->status)->toBe('done')
        ->and(Media::query()->where('disk', 's3')->count())->toBe(3)
        ->and($a->fresh()->disk)->toBe('s3');
});

it('does not try again a file the process died on', function () {
    $media = diskMedia('local');
    $run = mover()->start($this->actor, 's3');
    $run->inProgress = $media->id;
    app(DiskMoveRunStore::class)->save($run);

    $run = mover()->processBatch('cli', 60);

    expect($run->counts)->toBe(['failed' => 1])
        ->and($media->fresh()->disk)->toBe('local');
});

it('does nothing while another batch of the move holds the lock', function () {
    diskMedia('local');
    mover()->start($this->actor, 's3');
    $lock = Cache::lock(MoveMediaToDisk::LOCK, 60);
    $lock->get();

    expect(mover()->isBatchRunning())->toBeTrue()
        ->and(mover()->processBatch('cli', 60)->processed())->toBe(0);

    $lock->release();
});

it('pauses, resumes and stops a move', function () {
    diskMedia('local');
    mover()->start($this->actor, 's3');

    mover()->pause();
    expect(mover()->processBatch('cli', 60)->processed())->toBe(0);

    mover()->resume();
    expect(mover()->processBatch('cli', 60)->processed())->toBe(1);

    mover()->stop();
    expect(app(DiskMoveRunStore::class)->get())->toBeNull();
});

it('refuses an unknown or unconfigured target and users without media.edit', function () {
    expect(fn () => mover()->start($this->actor, 'ftp'))->toThrow(InvalidArgumentException::class);

    config(['filesystems.disks.s3.bucket' => null]);
    expect(fn () => mover()->start($this->actor, 's3'))->toThrow(InvalidArgumentException::class);

    $viewer = User::factory()->withPermissions(['admin.access', 'media.view'])->create();
    expect(fn () => mover()->start($viewer, 'local'))->toThrow(AuthorizationException::class)
        ->and(app(DiskMoveRunStore::class)->get())->toBeNull();
});
