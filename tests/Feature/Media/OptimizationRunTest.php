<?php

use App\Enums\MediaKind;
use App\Enums\OptimizeOutcome;
use App\Models\Media;
use App\Models\Setting;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeRun(array $overrides = []): OptimizationRun
{
    return OptimizationRun::fromArray(array_merge([
        'id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM',
        'status' => 'running',
        'fingerprint' => 'fp-1',
        'cursor' => 0,
        'total' => 10,
        'counts' => [],
        'bytes_before' => 0,
        'bytes_after' => 0,
        'started_at' => '2026-11-11T10:00:00+00:00',
        'heartbeat_at' => null,
        'driver' => null,
        'seconds_spent' => 0,
        'recent' => [],
    ], $overrides));
}

it('selects raster images that were not optimized with the current settings', function () {
    $null = Media::factory()->create(['mime' => 'image/jpeg', 'optimized_with' => null]);
    $old = Media::factory()->create(['mime' => 'image/webp', 'optimized_with' => 'old']);
    Media::factory()->create(['mime' => 'image/svg+xml', 'optimized_with' => null]);
    Media::factory()->create(['mime' => 'image/gif', 'animated' => true, 'optimized_with' => null]);
    Media::factory()->create(['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'optimized_with' => null]);
    Media::factory()->create(['mime' => 'image/webp', 'optimized_with' => 'current']);

    $ids = Media::query()->optimizationCandidates('current')->pluck('id')->all();

    expect($ids)->toBe([$null->id, $old->id]);
});

it('round-trips the run through the store', function () {
    $store = new OptimizationRunStore;
    $run = makeRun([
        'status' => 'paused', 'cursor' => 7, 'counts' => ['optimized' => 2, 'missing' => 1],
        'bytes_before' => 500, 'bytes_after' => 200, 'heartbeat_at' => '2026-11-11T10:05:00+00:00',
        'driver' => 'queue', 'seconds_spent' => 12.5,
        'recent' => [['name' => 'a.jpg', 'before' => 5, 'after' => 2, 'outcome' => 'optimized']],
    ]);

    expect($store->get())->toBeNull();

    $store->save($run);
    $store->save($run);

    expect(Setting::where('key', 'media_optimization_run')->count())->toBe(1)
        ->and($store->get()->toArray())->toBe($run->toArray())
        ->and($store->get()->id)->toBe('01JCAZ5V6N3QK8Y2W4X7R9T1PM')
        ->and($store->get()->heartbeatAt)->toBeInstanceOf(CarbonImmutable::class);

    $store->clear();

    expect($store->get())->toBeNull();
});

it('records outcomes, bytes and the newest ten files', function () {
    $run = makeRun();

    foreach (range(1, 12) as $i) {
        $media = Media::factory()->create(['name' => "f{$i}.jpg"]);
        $outcome = $i === 1 ? OptimizeOutcome::AlreadyOptimal : OptimizeOutcome::Optimized;
        $run->record($media, $outcome, 100, 40);
    }

    expect($run->processed())->toBe(12)
        ->and($run->counts)->toBe(['already_optimal' => 1, 'optimized' => 11])
        ->and($run->bytesBefore)->toBe(1100)
        ->and($run->bytesAfter)->toBe(440)
        ->and($run->cursor)->toBe($media->id)
        ->and($run->recent)->toHaveCount(10)
        ->and($run->recent[0]['name'])->toBe('f12.jpg');
});

it('knows when its fingerprint is stale', function () {
    $run = makeRun();

    expect($run->isStale('fp-1'))->toBeFalse()
        ->and($run->isStale('fp-2'))->toBeTrue();
});

it('estimates the time left from the average per file', function () {
    $run = makeRun();

    expect($run->secondsLeft(5))->toBeNull();

    $run->counts = ['optimized' => 4];
    $run->secondsSpent = 8.0;

    expect($run->secondsLeft(5))->toBe(10);
});

it('treats a malformed stored run as no run', function (mixed $value) {
    Setting::query()->create(['key' => 'media_optimization_run', 'value' => $value]);

    expect((new OptimizationRunStore)->get())->toBeNull();
})->with([
    'no status' => [['id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM', 'fingerprint' => 'fp', 'cursor' => 0, 'total' => 1, 'started_at' => '2026-11-11T10:00:00+00:00']],
    'unknown status' => [['id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM', 'status' => 'exploded', 'fingerprint' => 'fp', 'cursor' => 0, 'total' => 1, 'started_at' => '2026-11-11T10:00:00+00:00']],
    'no cursor' => [['id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM', 'status' => 'running', 'fingerprint' => 'fp', 'total' => 1, 'started_at' => '2026-11-11T10:00:00+00:00']],
    'bad start date' => [['id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM', 'status' => 'running', 'fingerprint' => 'fp', 'cursor' => 0, 'total' => 1, 'started_at' => 'not a date']],
    'bad heartbeat' => [['id' => '01JCAZ5V6N3QK8Y2W4X7R9T1PM', 'status' => 'running', 'fingerprint' => 'fp', 'cursor' => 0, 'total' => 1, 'started_at' => '2026-11-11T10:00:00+00:00', 'heartbeat_at' => 'yesterday-ish']],
    'a string' => ['broken'],
    'no id (saved before runs had one)' => [['status' => 'running', 'fingerprint' => 'fp', 'cursor' => 0, 'total' => 1, 'started_at' => '2026-11-11T10:00:00+00:00']],
]);
