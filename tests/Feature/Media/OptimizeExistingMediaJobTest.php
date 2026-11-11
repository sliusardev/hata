<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Jobs\OptimizeExistingMediaBatch;
use App\Models\Media;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A candidate JPEG row with a real file on the faked disk.
 */
function jobCandidate(): Media
{
    $media = Media::factory()->create(['mime' => 'image/jpeg', 'optimized_with' => null]);
    $image = imagecreatetruecolor(40, 30);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();
    $media->forceFill(['path' => "media/2025/01/{$media->key}.jpg", 'size' => strlen($bytes)])->save();
    Storage::disk('local')->put($media->path, $bytes);

    return $media;
}

it('dispatches itself again while candidates are left', function () {
    Queue::fake();
    jobCandidate();
    jobCandidate();
    app(OptimizeExistingMedia::class)->startWithoutActor();

    // A zero budget processes exactly one file per batch.
    $job = new class extends OptimizeExistingMediaBatch
    {
        public const float BUDGET = 0.0;
    };
    app()->call([$job, 'handle']);

    $run = app(OptimizationRunStore::class)->get();

    expect($run->status)->toBe('running')
        ->and($run->processed())->toBe(1)
        ->and($run->driver)->toBe('queue');
    Queue::assertPushed(OptimizeExistingMediaBatch::class, 1);
});

it('finishes the run and pushes nothing when no candidates are left', function () {
    Queue::fake();
    jobCandidate();
    app(OptimizeExistingMedia::class)->startWithoutActor();

    app()->call([new OptimizeExistingMediaBatch, 'handle']);

    expect(app(OptimizationRunStore::class)->get()->status)->toBe('done');
    Queue::assertNotPushed(OptimizeExistingMediaBatch::class);
});

it('does nothing without a run or while the run is paused', function () {
    Queue::fake();
    jobCandidate();

    app()->call([new OptimizeExistingMediaBatch, 'handle']);

    $engine = app(OptimizeExistingMedia::class);
    $engine->startWithoutActor();
    $engine->pause();
    app()->call([new OptimizeExistingMediaBatch, 'handle']);

    expect(app(OptimizationRunStore::class)->get()->processed())->toBe(0);
    Queue::assertNothingPushed();
});

it('has a budget that fits inside its timeout', function () {
    $job = new OptimizeExistingMediaBatch;

    expect($job->timeout)->toBe(60)
        ->and($job->tries)->toBe(1)
        ->and(OptimizeExistingMediaBatch::BUDGET)->toBeLessThan($job->timeout);
});
