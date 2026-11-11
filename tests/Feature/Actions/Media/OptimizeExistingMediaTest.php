<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use App\Enums\OptimizeOutcome;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\MemoryGuard;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

/**
 * A candidate media row with its current file (and optionally a kept original) on the faked disk.
 *
 * @param  array<string, mixed>  $attributes
 */
function optimizableMedia(string $current, string $mime = 'image/jpeg', ?string $original = null, array $attributes = []): Media
{
    $media = Media::factory()->create($attributes + ['mime' => $mime, 'optimized_with' => null]);
    $extension = $mime === 'image/jpeg' ? 'jpg' : 'webp';
    $media->forceFill([
        'path' => "media/2025/01/{$media->key}.{$extension}",
        'name' => pathinfo($media->name, PATHINFO_FILENAME).".{$extension}",
        'size' => strlen($current),
        'original_path' => $original === null ? null : "media/originals/{$media->key}.jpg",
    ])->save();

    Storage::disk('local')->put($media->path, $current);

    if ($original !== null) {
        Storage::disk('local')->put((string) $media->original_path, $original);
    }

    return $media;
}

/**
 * WebP bytes of the image as the optimizer stores it with the current settings.
 */
function optimizedBytes(string $contents, string $mime = 'image/jpeg'): string
{
    return app(OptimizeImage::class)->handle($contents, $mime)->contents;
}

/**
 * JPEG bytes of a noisy image, whose encoded size grows clearly with the quality (flat test images don't).
 */
function noisyJpeg(int $width, int $height): string
{
    mt_srand(42);
    $image = imagecreatetruecolor($width, $height);

    for ($y = 0; $y < $height; $y += 4) {
        for ($x = 0; $x < $width; $x += 4) {
            imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        }
    }

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

function engine(): OptimizeExistingMedia
{
    return app(OptimizeExistingMedia::class);
}

function currentFingerprint(): string
{
    return app(OptimizeImage::class)->fingerprint();
}

it('optimizes a file without an original and keeps its bytes as the original', function () {
    $bytes = makeImage('jpg', 3000, 2000);
    $media = optimizableMedia($bytes);
    $key = $media->key;

    [$outcome, $before, $after] = engine()->optimizeOne($media);
    $media->refresh();
    $disk = Storage::disk('local');

    expect($outcome)->toBe(OptimizeOutcome::Optimized)
        ->and($before)->toBe(strlen($bytes))
        ->and($after)->toBe($media->size)
        ->and($media->mime)->toBe('image/webp')
        ->and(max($media->width, $media->height))->toBeLessThanOrEqual(2560)
        ->and($media->version)->toBe(2)
        ->and($media->optimized_with)->toBe(currentFingerprint())
        ->and($media->original_path)->toBe("media/originals/{$media->key}.jpg")
        ->and($disk->get((string) $media->original_path))->toBe($bytes)
        ->and($media->key)->toBe($key);
    $disk->assertExists($media->path);
});

it('marks a file already optimal when its current bytes do not shrink', function () {
    $bytes = optimizedBytes(noisyJpeg(600, 400));
    config(['media.optimize.quality' => 95]);
    $media = optimizableMedia($bytes, 'image/webp', attributes: ['optimized_with' => 'webp:2560:80:exif0']);

    [$outcome, $before, $after] = engine()->optimizeOne($media);
    $media->refresh();

    expect($outcome)->toBe(OptimizeOutcome::AlreadyOptimal)
        ->and($before)->toBe($after)
        ->and($media->version)->toBe(1)
        ->and($media->original_path)->toBeNull()
        ->and($media->optimized_with)->toBe(currentFingerprint())
        ->and(Storage::disk('local')->get($media->path))->toBe($bytes);
});

it('writes the result from a kept original even when it is bigger than the current file', function () {
    $original = noisyJpeg(600, 400);
    $media = optimizableMedia(optimizedBytes($original), 'image/webp', $original, ['optimized_with' => 'webp:2560:80:exif0']);
    config(['media.optimize.quality' => 95]);

    [$outcome, $before, $after] = engine()->optimizeOne($media);
    $media->refresh();

    expect($outcome)->toBe(OptimizeOutcome::Optimized)
        ->and($after)->toBeGreaterThan($before)
        ->and($media->size)->toBe($after)
        ->and($media->version)->toBe(2)
        ->and(Storage::disk('local')->get((string) $media->original_path))->toBe($original);
});

it('re-applies stored crop edits from the original', function () {
    $original = makeImage('jpg', 3000, 2000);
    $edits = ['rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'crop' => ['x' => 0, 'y' => 0, 'w' => 1000, 'h' => 500], 'size' => null];
    $media = optimizableMedia(makeImage('jpg', 1000, 500), original: $original, attributes: ['edits' => $edits]);

    [$outcome] = engine()->optimizeOne($media);
    $media->refresh();
    $stored = Image::fromBytes((string) Storage::disk('local')->get($media->path));

    expect($outcome)->toBe(OptimizeOutcome::Optimized)
        ->and([$media->width, $media->height])->toBe([1000, 500])
        ->and([$stored->width(), $stored->height()])->toBe([1000, 500])
        ->and($media->edits)->toBe($edits);
});

it('skips a file too large for memory and leaves it a candidate', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(1024));
    $bytes = makeImage('jpg', 1200, 800);
    $media = optimizableMedia($bytes);

    [$outcome, $before, $after] = engine()->optimizeOne($media);
    $media->refresh();

    expect($outcome)->toBe(OptimizeOutcome::TooLarge)
        ->and($before)->toBe($after)
        ->and($media->version)->toBe(1)
        ->and($media->original_path)->toBeNull()
        ->and(Storage::disk('local')->get($media->path))->toBe($bytes)
        ->and(Media::query()->optimizationCandidates(currentFingerprint())->pluck('id')->all())->toBe([$media->id]);
});

it('records a missing file and continues with the next one', function () {
    $missing = optimizableMedia(makeImage('jpg', 400, 300));
    Storage::disk('local')->delete($missing->path);
    $next = optimizableMedia(makeImage('jpg', 400, 300));

    expect(engine()->optimizeOne($missing)[0])->toBe(OptimizeOutcome::Missing);

    engine()->startWithoutActor();
    $run = engine()->processBatch('cli', 60);

    expect($run->status)->toBe('done')
        ->and($run->counts)->toBe(['missing' => 1, 'optimized' => 1])
        ->and($run->cursor)->toBe($next->id)
        ->and($next->fresh()->optimized_with)->toBe(currentFingerprint());
});

it('processes one file per zero-budget batch and advances the cursor', function () {
    $first = optimizableMedia(makeImage('jpg', 400, 300));
    $second = optimizableMedia(makeImage('jpg', 400, 300));
    engine()->start($this->actor);

    $run = engine()->processBatch('browser', 0);

    expect($run->cursor)->toBe($first->id)
        ->and($run->processed())->toBe(1)
        ->and($run->status)->toBe('running')
        ->and($run->driver)->toBe('browser')
        ->and($run->heartbeatAt)->not->toBeNull()
        ->and(app(OptimizationRunStore::class)->get()->cursor)->toBe($first->id);

    $run = engine()->processBatch('browser', 0);

    expect($run->cursor)->toBe($second->id)
        ->and($run->processed())->toBe(2)
        ->and($run->status)->toBe('done');
});

it('visits skipped files once per run and ends done with counts', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(1024));
    optimizableMedia(makeImage('jpg', 400, 300));
    optimizableMedia(makeImage('jpg', 400, 300));
    $run = engine()->startWithoutActor();

    expect($run->total)->toBe(2)
        ->and($run->fingerprint)->toBe(currentFingerprint())
        ->and($run->heartbeatAt)->not->toBeNull();

    engine()->processBatch('browser', 0);
    engine()->processBatch('browser', 0);
    $run = engine()->processBatch('browser', 0);

    expect($run->status)->toBe('done')
        ->and($run->counts)->toBe(['too_large' => 2])
        ->and($run->bytesBefore)->toBe(0)
        ->and($run->bytesAfter)->toBe(0)
        ->and(Media::query()->optimizationCandidates(currentFingerprint())->count())->toBe(2);
});

it('does nothing for a stale run, a held lock or optimization off', function (string $case) {
    optimizableMedia(makeImage('jpg', 400, 300));
    engine()->startWithoutActor();

    match ($case) {
        'stale' => config(['media.optimize.quality' => 60]),
        'held lock' => Cache::lock('media-optimization-batch', 60)->get(),
        'optimization off' => config(['media.optimize.enabled' => false]),
    };

    $run = engine()->processBatch('browser', 0);

    expect($run->processed())->toBe(0)
        ->and($run->cursor)->toBe(0)
        ->and($run->status)->toBe('running');
})->with(['stale', 'held lock', 'optimization off']);

it('does nothing for a paused run until it is resumed', function () {
    optimizableMedia(makeImage('jpg', 400, 300));
    engine()->startWithoutActor();
    engine()->pause();

    expect(engine()->processBatch('browser', 0)->processed())->toBe(0);

    engine()->resume();
    $run = engine()->processBatch('browser', 0);

    expect($run->processed())->toBe(1)
        ->and($run->status)->toBe('done');
});

it('returns null without a run and clears it on stop', function () {
    expect(engine()->processBatch('browser', 0))->toBeNull();

    engine()->startWithoutActor();
    engine()->stop();

    expect(app(OptimizationRunStore::class)->get())->toBeNull();
});

it('refuses to start without media.edit', function () {
    $viewer = User::factory()->withPermissions(['admin.access', 'media.view'])->create();

    expect(fn () => engine()->start($viewer))->toThrow(AuthorizationException::class)
        ->and(app(OptimizationRunStore::class)->get())->toBeNull();
});
