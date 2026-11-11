<?php

use App\Actions\Media\MoveMediaToDisk;
use App\Jobs\MoveMediaBatch;
use App\Models\Media;
use App\Support\Media\DiskMoveRunStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    fakeCloudDisk('s3');
    config(['filesystems.disks.s3.bucket' => 'media']);
});

/**
 * A local row with its file on the faked disk.
 */
function moveJobCandidate(): Media
{
    $media = Media::factory()->create(['disk' => 'local', 'size' => 4]);
    Storage::disk('local')->put($media->path, 'data');

    return $media;
}

it('dispatches itself again while files are left', function () {
    Queue::fake();
    moveJobCandidate();
    moveJobCandidate();
    $started = app(MoveMediaToDisk::class)->startWithoutActor('s3');

    // A zero budget moves exactly one file per batch.
    $job = new class($started->id) extends MoveMediaBatch
    {
        public const float BUDGET = 0.0;
    };
    app()->call([$job, 'handle']);

    $run = app(DiskMoveRunStore::class)->get();

    expect($run->processed())->toBe(1)
        ->and($run->driver)->toBe('queue');
    Queue::assertPushed(MoveMediaBatch::class, fn (MoveMediaBatch $next) => $next->runId === $started->id && $next->delay === null);
});

it('stops once the move is done', function () {
    Queue::fake();
    moveJobCandidate();
    $run = app(MoveMediaToDisk::class)->startWithoutActor('s3');

    app()->call([new MoveMediaBatch($run->id), 'handle']);

    expect(app(DiskMoveRunStore::class)->get()->status)->toBe('done');
    Queue::assertNothingPushed();
});

it('waits before retrying while another batch holds the lock', function () {
    Queue::fake();
    moveJobCandidate();
    $run = app(MoveMediaToDisk::class)->startWithoutActor('s3');
    $lock = Cache::lock(MoveMediaToDisk::LOCK, 60);
    $lock->get();

    app()->call([new MoveMediaBatch($run->id), 'handle']);

    Queue::assertPushed(MoveMediaBatch::class, fn ($job) => $job->delay !== null);

    $lock->release();
});

it('stops when the run it was queued for was replaced', function () {
    Queue::fake();
    moveJobCandidate();
    $engine = app(MoveMediaToDisk::class);
    $old = $engine->startWithoutActor('s3');
    $engine->stop();
    $engine->startWithoutActor('s3');

    app()->call([new MoveMediaBatch($old->id), 'handle']);

    expect(app(DiskMoveRunStore::class)->get()->processed())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps the job timeout and the queue batch lock inside the connection\'s retry window', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
    $engine = app(MoveMediaToDisk::class);

    expect((new MoveMediaBatch('run'))->timeout)->toBeLessThan(90)->toBeGreaterThan(0)
        ->and($engine->lockSeconds(MoveMediaBatch::BUDGET, 'queue'))->toBeLessThan(90)
        ->and((new MoveMediaBatch('run'))->timeout)->toBe($engine->lockSeconds(MoveMediaBatch::BUDGET, 'queue'));
});

it('lets the job timeout and the queue lock follow a larger retry_after, capped by the file time', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 600]);
    $engine = app(MoveMediaToDisk::class);

    expect((new MoveMediaBatch('run'))->timeout)->toBe(590)
        ->and($engine->lockSeconds(MoveMediaBatch::BUDGET, 'queue'))->toBe(590);

    config(['queue.connections.database.retry_after' => 7200]);

    expect((new MoveMediaBatch('run'))->timeout)->toBe((int) MoveMediaBatch::BUDGET + MoveMediaToDisk::FILE_SECONDS);
});

it('uses the retry_after of the connection the job is sent to', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 600, 'queue.connections.redis.retry_after' => 90]);

    $job = (new MoveMediaBatch('run'))->onConnection('redis');

    expect($job->timeout)->toBeLessThan(90);
});

it('keeps the browser batch lock short and the CLI free of the retry window', function () {
    config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);
    $engine = app(MoveMediaToDisk::class);

    expect($engine->lockSeconds(10.0, 'browser'))->toBe(70)
        ->and($engine->lockSeconds(60.0, 'cli'))->toBe(60 + MoveMediaToDisk::FILE_SECONDS);
});
