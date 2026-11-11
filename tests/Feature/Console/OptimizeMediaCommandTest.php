<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Models\Media;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A candidate JPEG row with a real file on the faked disk.
 */
function commandCandidate(): Media
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

it('optimizes every candidate and reports progress', function () {
    foreach (range(1, 3) as $_) {
        commandCandidate();
    }

    $this->artisan('media:optimize')
        ->expectsOutputToContain('3/3')
        ->assertSuccessful();

    $run = app(OptimizationRunStore::class)->get();

    expect($run->status)->toBe('done')
        ->and($run->processed())->toBe(3)
        ->and($run->counts['optimized'] ?? 0)->toBe(3)
        ->and($run->driver)->toBe('cli');
});

it('resumes a paused run instead of starting over', function () {
    commandCandidate();
    commandCandidate();
    $engine = app(OptimizeExistingMedia::class);
    $engine->startWithoutActor();
    $engine->pause();

    $this->artisan('media:optimize')->assertSuccessful();

    $run = app(OptimizationRunStore::class)->get();

    expect($run->status)->toBe('done')
        ->and($run->total)->toBe(2)
        ->and($run->processed())->toBe(2);
});

it('starts a fresh run with --restart', function () {
    commandCandidate();
    commandCandidate();
    $this->artisan('media:optimize')->assertSuccessful();

    // Everything is optimized now; a plain run would start over too (the run is done), so add one more candidate.
    commandCandidate();
    $this->artisan('media:optimize --restart')->assertSuccessful();

    $run = app(OptimizationRunStore::class)->get();

    expect($run->total)->toBe(1)
        ->and($run->processed())->toBe(1);
});

it('fails when optimization is off', function () {
    config(['media.optimize.enabled' => false]);

    $this->artisan('media:optimize')
        ->expectsOutputToContain('Optimization is off in the media settings.')
        ->assertFailed();

    expect(app(OptimizationRunStore::class)->get())->toBeNull();
});

it('starts over when the stored run is stale', function () {
    commandCandidate();
    app(OptimizeExistingMedia::class)->startWithoutActor();
    config(['media.optimize.quality' => 55]);

    $this->artisan('media:optimize')->assertSuccessful();

    expect(app(OptimizationRunStore::class)->get()->total)->toBe(1);
});
