<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Jobs\OptimizeExistingMediaBatch;
use App\Models\Media;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Cache;
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
    $started = app(OptimizeExistingMedia::class)->startWithoutActor();

    // A zero budget processes exactly one file per batch.
    $job = new class($started->id) extends OptimizeExistingMediaBatch
    {
        public const float BUDGET = 0.0;
    };
    app()->call([$job, 'handle']);

    $run = app(OptimizationRunStore::class)->get();

    expect($run->status)->toBe('running')
        ->and($run->processed())->toBe(1)
        ->and($run->driver)->toBe('queue');
    Queue::assertPushed(OptimizeExistingMediaBatch::class, fn (OptimizeExistingMediaBatch $next) => $next->runId === $started->id);
});

it('finishes the run and pushes nothing when no candidates are left', function () {
    Queue::fake();
    jobCandidate();
    $run = app(OptimizeExistingMedia::class)->startWithoutActor();

    app()->call([new OptimizeExistingMediaBatch($run->id), 'handle']);

    expect(app(OptimizationRunStore::class)->get()->status)->toBe('done');
    Queue::assertNotPushed(OptimizeExistingMediaBatch::class);
});

it('does nothing without a run or while the run is paused', function () {
    Queue::fake();
    jobCandidate();

    app()->call([new OptimizeExistingMediaBatch('01JCAZ5V6N3QK8Y2W4X7R9T1PM'), 'handle']);

    $engine = app(OptimizeExistingMedia::class);
    $run = $engine->startWithoutActor();
    $engine->pause();
    app()->call([new OptimizeExistingMediaBatch($run->id), 'handle']);

    expect(app(OptimizationRunStore::class)->get()->processed())->toBe(0);
    Queue::assertNothingPushed();
});

it('has a budget that fits inside its timeout', function () {
    $job = new OptimizeExistingMediaBatch('01JCAZ5V6N3QK8Y2W4X7R9T1PM');

    expect($job->timeout)->toBe(60)
        ->and($job->tries)->toBe(1)
        ->and(OptimizeExistingMediaBatch::BUDGET)->toBeLessThan($job->timeout);
});

it('waits before retrying while another batch holds the lock', function () {
    Queue::fake();
    jobCandidate();
    $run = app(OptimizeExistingMedia::class)->startWithoutActor();
    $lock = Cache::lock(OptimizeExistingMedia::LOCK, 60);
    $lock->get();

    app()->call([new OptimizeExistingMediaBatch($run->id), 'handle']);

    expect(app(OptimizationRunStore::class)->get()->processed())->toBe(0);
    Queue::assertPushed(OptimizeExistingMediaBatch::class, fn ($job) => $job->delay !== null);

    $lock->release();
});

it('stops without dispatching again when optimization was turned off during the run', function () {
    Queue::fake();
    jobCandidate();
    $run = app(OptimizeExistingMedia::class)->startWithoutActor();
    config(['media.optimize.enabled' => false]);

    app()->call([new OptimizeExistingMediaBatch($run->id), 'handle']);

    expect(app(OptimizationRunStore::class)->get()->status)->toBe('paused');
    Queue::assertNothingPushed();
});

it('stops when the run it was queued for was replaced, so an old chain dies out', function () {
    Queue::fake();
    jobCandidate();
    $engine = app(OptimizeExistingMedia::class);
    $old = $engine->startWithoutActor();
    $engine->stop();
    $new = $engine->startWithoutActor();

    app()->call([new OptimizeExistingMediaBatch($old->id), 'handle']);

    expect(app(OptimizationRunStore::class)->get()->id)->toBe($new->id)
        ->and(app(OptimizationRunStore::class)->get()->processed())->toBe(0);
    Queue::assertNothingPushed();
});
