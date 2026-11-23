<?php

use App\Models\User;
use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Actions\Media\PruneMediaDeletions;
use Hata\Actions\Media\RebuildMediaFile;
use Hata\Enums\MediaKind;
use Hata\Enums\MoveOutcome;
use Hata\Exceptions\MediaWriteException;
use Hata\Models\Media;
use Hata\Models\MediaPendingDeletion;
use Hata\Support\Media\DiskMoveRunStore;
use Hata\Support\Media\MediaDisks;
use Hata\Support\Media\OptimizationRunStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RecordingDisk;

beforeEach(function () {
    $this->local = recordingLocalDisk('local');
    $this->public = recordingLocalDisk('public');
    $cloud = fakeCloudDisk('s3');
    config(['filesystems.disks.s3.url' => 'https://cdn.test', 'filesystems.disks.s3.bucket' => 'media']);
    $this->cloud = $cloud;
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

/**
 * A faked local disk that records its writes and can be told to fail them (see RecordingDisk).
 */
function recordingLocalDisk(string $disk): RecordingDisk
{
    /** @var FilesystemAdapter $fake */
    $fake = Storage::fake($disk);
    $recording = new RecordingDisk($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
    Storage::set($disk, $recording);

    return $recording;
}

/**
 * A JPEG row on the given disk with its file and thumbnail written there and its original on the disk's originals
 * disk (paths versioned like uploads where the disk versions them).
 */
function diskMedia(string $disk = 'local', int $version = 1): Media
{
    $media = Media::factory()->create(['disk' => $disk, 'mime' => 'image/jpeg', 'version' => $version]);
    $suffix = MediaDisks::versionsPaths($disk) ? "-v{$version}" : '';
    $media->forceFill([
        'path' => "media/2025/01/{$media->key}{$suffix}.jpg",
        'thumb_path' => "media/thumbs/{$media->key}{$suffix}.webp",
        'original_path' => "media/originals/{$media->key}{$suffix}.png",
        'name' => "{$media->key}.jpg",
        'size' => 9,
    ])->save();

    Storage::disk($disk)->put($media->path, 'file-data');
    Storage::disk($disk)->put($media->thumb_path, 'thumb');
    Storage::disk(MediaDisks::originalsDisk($disk))->put($media->original_path, 'original-bytes');

    return $media;
}

/**
 * A row that is not a raster image (a PDF or an SVG) on the given disk, with only its file written there.
 */
function diskNonRaster(string $disk, string $type = 'pdf', int $version = 1): Media
{
    [$kind, $mime] = $type === 'svg' ? [MediaKind::Image, 'image/svg+xml'] : [MediaKind::Document, 'application/pdf'];
    $suffix = MediaDisks::versionsPaths($disk) ? "-v{$version}" : '';
    $media = Media::factory()->create(['disk' => $disk, 'kind' => $kind, 'mime' => $mime, 'version' => $version, 'size' => 5]);
    $media->forceFill([
        'path' => "media/2025/01/{$media->key}{$suffix}.{$type}",
        'thumb_path' => null,
        'original_path' => null,
        'name' => "{$media->key}.{$type}",
    ])->save();

    Storage::disk($disk)->put($media->path, $type === 'svg' ? '<svg/>' : '%PDF-');

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

it('moves a cloud image to this server, its original to the private disk, and keeps the old objects for seven days', function () {
    $media = diskMedia('s3', version: 3);
    $old = [$media->path, $media->thumb_path, $media->original_path];

    expect(mover()->moveOne($media, 'public'))->toBe(MoveOutcome::Moved);

    $media->refresh();
    expect($media->disk)->toBe('public')
        ->and($media->version)->toBe(4)
        ->and($media->path)->toEndWith("{$media->key}-v4.jpg")
        ->and($media->thumb_path)->toBe("media/thumbs/{$media->key}-v4.webp")
        ->and($media->original_path)->toBe("media/originals/{$media->key}-v4.png")
        ->and($media->url())->toContain('/media/'.$media->key.'/')->toContain('?v=4');
    expect(Storage::disk('public')->get($media->path))->toBe('file-data')
        ->and(Storage::disk('public')->get($media->thumb_path))->toBe('thumb')
        ->and(Storage::disk('local')->get($media->original_path))->toBe('original-bytes');
    Storage::disk('public')->assertMissing($media->original_path);

    // Pages and CDN caches rendered before still find the old objects until the deferred deletion.
    foreach ($old as $path) {
        Storage::disk('s3')->assertExists($path);
    }
    expect(MediaPendingDeletion::query()->where('disk', 's3')->pluck('path')->sort()->values()->all())->toBe(collect($old)->sort()->values()->all())
        ->and(MediaPendingDeletion::query()->where('disk', '!=', 's3')->count())->toBe(0);
});

it('moves a local image to this server under versioned paths, keeps its original private and deletes the old files at once', function () {
    $media = diskMedia('local');
    $old = [$media->path, $media->thumb_path, $media->original_path];

    expect(mover()->moveOne($media, 'public'))->toBe(MoveOutcome::Moved);

    $media->refresh();
    expect($media->disk)->toBe('public')
        ->and($media->version)->toBe(2)
        ->and($media->path)->toBe('media/'.now()->format('Y/m')."/{$media->key}-v2.jpg")
        ->and($media->thumb_path)->toBe("media/thumbs/{$media->key}-v2.webp")
        ->and($media->original_path)->toBe("media/originals/{$media->key}-v2.png")
        ->and($media->originalsDisk())->toBe('local');
    expect(Storage::disk('public')->allFiles())->toEqualCanonicalizing([$media->path, $media->thumb_path])
        ->and(Storage::disk('local')->allFiles())->toBe([$media->original_path])
        ->and(Storage::disk('local')->get($media->original_path))->toBe('original-bytes')
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
    foreach ($old as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});

it('leaves an svg and a document on the private disk when moving to this server, and does not count them', function () {
    $svg = diskNonRaster('local', 'svg');
    $pdf = diskNonRaster('local', 'pdf');
    $image = diskMedia('local');

    expect(mover()->moveOne($svg, 'public'))->toBe(MoveOutcome::Changed)
        ->and(mover()->moveOne($pdf, 'public'))->toBe(MoveOutcome::Changed)
        ->and($svg->fresh()->version)->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $run = mover()->start($this->actor, 'public');
    expect($run->total)->toBe(1);

    $run = mover()->processBatch('cli', 60);
    expect($run->counts)->toBe(['moved' => 1])
        ->and($image->fresh()->disk)->toBe('public')
        ->and($svg->fresh()->disk)->toBe('local')
        ->and($pdf->fresh()->disk)->toBe('local');
});

it('moves a cloud svg and document to the private disk when moving to this server', function () {
    $svg = diskNonRaster('s3', 'svg', version: 2);
    $pdf = diskNonRaster('s3', 'pdf', version: 2);
    $old = [$svg->path, $pdf->path];

    expect(mover()->moveOne($svg, 'public'))->toBe(MoveOutcome::Moved)
        ->and(mover()->moveOne($pdf, 'public'))->toBe(MoveOutcome::Moved);

    foreach ([$svg->refresh(), $pdf->refresh()] as $media) {
        expect($media->disk)->toBe('local')
            ->and($media->version)->toBe(3)
            ->and($media->path)->toBe('media/'.now()->format('Y/m').'/'.$media->key.'.'.pathinfo($media->name, PATHINFO_EXTENSION));
        Storage::disk('local')->assertExists($media->path);
    }
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(MediaPendingDeletion::query()->where('disk', 's3')->pluck('path')->sort()->values()->all())->toBe(collect($old)->sort()->values()->all());
});

it('moves an image from this server to the cloud with its private original, deferring the public files only', function () {
    $media = diskMedia('public', version: 2);
    [$path, $thumb, $original] = [$media->path, $media->thumb_path, $media->original_path];

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Moved);

    $media->refresh();
    expect($media->disk)->toBe('s3')
        ->and($media->version)->toBe(3)
        ->and($media->original_path)->toBe("media/originals/{$media->key}-v3.png")
        ->and(Storage::disk('s3')->get($media->path))->toBe('file-data')
        ->and(Storage::disk('s3')->get($media->original_path))->toBe('original-bytes');

    // Static links rendered before keep working until the deferred deletion; the private original goes at once.
    Storage::disk('public')->assertExists($path);
    Storage::disk('public')->assertExists($thumb);
    Storage::disk('local')->assertMissing($original);
    expect(MediaPendingDeletion::query()->where('disk', 'public')->pluck('path')->sort()->values()->all())->toBe(collect([$path, $thumb])->sort()->values()->all())
        ->and(MediaPendingDeletion::query()->where('disk', '!=', 'public')->count())->toBe(0);
});

it('moves a private document to the cloud', function () {
    $pdf = diskNonRaster('local', 'pdf');
    $old = $pdf->path;

    expect(mover()->moveOne($pdf, 's3'))->toBe(MoveOutcome::Moved);

    expect($pdf->refresh()->disk)->toBe('s3')
        ->and($pdf->path)->toEndWith("{$pdf->key}-v2.pdf")
        ->and(Storage::disk('s3')->get($pdf->path))->toBe('%PDF-')
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($old);
});

it('leaves no copy on any disk and the row untouched when the original fails to copy to the private disk', function () {
    $media = diskMedia('s3', version: 2);
    $before = $media->fresh()->getAttributes();
    $this->local->beforeWrite = function (string $path): void {
        if (str_contains($path, 'originals/')) {
            throw new RuntimeException('Disk full');
        }
    };

    expect(mover()->moveOne($media, 'public'))->toBe(MoveOutcome::Failed);

    expect($media->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
    foreach ([$media->path, $media->thumb_path, $media->original_path] as $path) {
        Storage::disk('s3')->assertExists($path);
    }
});

it('insures each copy on the disk it is written to and drops the insurance on success', function () {
    $media = diskMedia('s3');
    $seen = null;
    $this->local->beforeWrite = function () use (&$seen): void {
        $seen = MediaPendingDeletion::query()->orderBy('disk')->get()
            ->map(fn (MediaPendingDeletion $row): array => [$row->disk, $row->path, $row->delete_after->diffInHours(now(), true) > 23])->all();
    };

    expect(mover()->moveOne($media, 'public'))->toBe(MoveOutcome::Moved);
    $media->refresh();

    expect($seen)->toEqualCanonicalizing([
        ['local', $media->original_path, true],
        ['public', $media->path, true],
        ['public', $media->thumb_path, true],
    ])->and(MediaPendingDeletion::query()->where('disk', '!=', 's3')->count())->toBe(0);
});

it('picks exactly the rows whose disk differs from where the target puts them', function () {
    $rows = [
        'local image' => diskMedia('local'),
        'public image' => diskMedia('public'),
        'cloud image' => diskMedia('s3'),
        'local pdf' => diskNonRaster('local', 'pdf'),
        'local svg' => diskNonRaster('local', 'svg'),
        'cloud pdf' => diskNonRaster('s3', 'pdf'),
        'cloud svg' => diskNonRaster('s3', 'svg'),
        'public pdf' => diskNonRaster('public', 'pdf'),
    ];

    foreach (MoveMediaToDisk::TARGETS as $target) {
        $expected = collect($rows)->filter(fn (Media $media): bool => $media->disk !== MoveMediaToDisk::destinationFor($media, $target))->keys()->sort()->values()->all();
        $picked = MoveMediaToDisk::candidates($target)->pluck('id')->map(fn (int $id): string => array_search($id, array_map(fn (Media $media): int => $media->id, $rows), true))->sort()->values()->all();

        expect($picked)->toBe($expected);
    }

    expect(MoveMediaToDisk::candidates('public')->count())->toBe(5)
        ->and(MoveMediaToDisk::candidates('s3')->count())->toBe(5);
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

it('does not count a row already on the target as moved', function () {
    $media = diskMedia('s3');

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Changed)
        ->and($media->fresh()->version)->toBe(1);
});

it('insures every copy as a pending deletion while it is written and drops the insurance on success', function () {
    $media = diskMedia('local');
    $seen = [];
    $this->cloud->beforeWrite = function () use (&$seen): void {
        $seen[] = MediaPendingDeletion::query()->where('disk', 's3')->get()
            ->map(fn (MediaPendingDeletion $row): array => [$row->path, $row->delete_after->diffInHours(now(), true)])->all();
    };

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Moved);
    $media->refresh();

    $insured = collect($seen[0])->pluck(0)->sort()->values()->all();
    expect($insured)->toBe(collect([$media->path, $media->thumb_path, $media->original_path])->sort()->values()->all())
        ->and(collect($seen[0])->pluck(1)->every(fn (float $hours): bool => $hours > 23 && $hours <= 24))->toBeTrue()
        ->and($seen)->toHaveCount(3)
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
});

it('drops the insurance of a failed move once its copies are removed, and keeps it when they could not be', function () {
    $media = diskMedia('local');
    $this->cloud->beforeWrite = function (string $path): void {
        if (str_contains($path, 'originals/')) {
            throw new RuntimeException('Network down');
        }
    };

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Failed)
        ->and(MediaPendingDeletion::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);

    $this->cloud->failDeletes = true;

    expect(mover()->moveOne($media, 's3'))->toBe(MoveOutcome::Failed);
    $left = MediaPendingDeletion::query()->where('disk', 's3')->pluck('path')->sort()->values()->all();
    // Every copy written (and the one being written when it failed) stays insured for the prune.
    expect(Storage::disk('s3')->allFiles())->toHaveCount(2)
        ->and(array_diff(Storage::disk('s3')->allFiles(), $left))->toBe([])
        ->and($left)->toHaveCount(3);
});

it('sweeps the copies a move left when its process died, never a path a row points at', function () {
    $media = diskMedia('local');
    $left = [];
    // Record what is insured and written at the moment the process "dies" on its last copy.
    $this->cloud->beforeWrite = function (string $path) use (&$left): void {
        if (str_contains($path, 'originals/')) {
            $left = MediaPendingDeletion::query()->get(['disk', 'path', 'delete_after'])->toArray();

            throw new RuntimeException('killed');
        }
    };
    mover()->moveOne($media, 's3');
    $this->cloud->beforeWrite = null;

    // The dead process never cleaned up: its copies and their insurance stay.
    foreach ($left as $row) {
        MediaPendingDeletion::query()->create($row);
        Storage::disk('s3')->put($row['path'], 'partial');
    }
    $committed = diskMedia('s3');
    $committed->forceFill(['path' => $left[0]['path']])->save();

    $this->travel(25)->hours();
    app(PruneMediaDeletions::class)->handle();

    Storage::disk('s3')->assertExists($left[0]['path']);
    foreach (array_slice($left, 1) as $row) {
        Storage::disk('s3')->assertMissing($row['path']);
    }
    expect(MediaPendingDeletion::query()->count())->toBe(0)
        ->and($media->fresh()->disk)->toBe('local');
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
    expect(fn () => mover()->start($this->actor, 'ftp'))->toThrow(InvalidArgumentException::class)
        // Everything to the private disk is not a direction: this server keeps images public and the rest private.
        ->and(fn () => mover()->start($this->actor, 'local'))->toThrow(InvalidArgumentException::class, 'use one of: public, s3');

    config(['filesystems.disks.s3.bucket' => null]);
    expect(fn () => mover()->start($this->actor, 's3'))->toThrow(InvalidArgumentException::class);

    $viewer = User::factory()->withPermissions(['admin.access', 'media.view'])->create();
    expect(fn () => mover()->start($viewer, 'public'))->toThrow(AuthorizationException::class)
        ->and(app(DiskMoveRunStore::class)->get())->toBeNull();
});

/**
 * A mover whose time-limit functions are seams: it records the calls and can pretend they are disabled.
 */
function moverWithTimeLimitFunctions(bool $available, array &$calls): MoveMediaToDisk
{
    return new class(app(DiskMoveRunStore::class), $available, $calls) extends MoveMediaToDisk
    {
        public function __construct(DiskMoveRunStore $runs, private bool $available, private array &$calls)
        {
            parent::__construct($runs);
        }

        protected function functionExists(string $function): bool
        {
            return $this->available;
        }

        protected function setTimeLimit(int $seconds): void
        {
            $this->calls[] = ['set_time_limit', $seconds];
        }

        protected function ignoreUserAbort(bool $ignore): int
        {
            $this->calls[] = ['ignore_user_abort', $ignore];

            return 0;
        }
    };
}

it('lifts the time limit and ignores aborts around a copy, then restores the abort setting', function () {
    $calls = [];

    expect(moverWithTimeLimitFunctions(true, $calls)->moveOne(diskMedia('local'), 's3'))->toBe(MoveOutcome::Moved)
        ->and($calls)->toBe([['set_time_limit', 0], ['ignore_user_abort', true], ['ignore_user_abort', false]]);
});

it('still moves a file when the time-limit functions are disabled on the host', function () {
    $calls = [];
    $media = diskMedia('local');

    expect(moverWithTimeLimitFunctions(false, $calls)->moveOne($media, 's3'))->toBe(MoveOutcome::Moved)
        ->and($calls)->toBe([])
        ->and($media->refresh()->disk)->toBe('s3');
});

it('refuses to copy an svg onto the publishing disk and writes nothing', function () {
    $svg = diskNonRaster('local', 'svg');

    expect(fn () => (fn () => $this->copy(Storage::disk('local'), $svg->path, 'public', "media/2026/11/{$svg->key}-v2.svg", 'image/svg+xml'))->call(mover()))
        ->toThrow(MediaWriteException::class);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('deletes only the new versioned original when a move from the private disk to this server fails after copying it', function () {
    $media = diskMedia('local');
    $before = $media->fresh()->getAttributes();
    $localBefore = Storage::disk('local')->allFiles();
    Media::updating(fn () => throw new RuntimeException('boom'));

    expect(mover()->moveOne($media, 'public'))->toBe(MoveOutcome::Failed);

    expect($this->local->options)->toHaveKey("media/originals/{$media->key}-v2.png")
        ->and($media->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing($localBefore)
        ->and(Storage::disk('local')->get((string) $media->original_path))->toBe('original-bytes')
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
});
