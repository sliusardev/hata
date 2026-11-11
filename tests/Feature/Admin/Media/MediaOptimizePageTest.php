<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use App\Actions\Media\RebuildMediaFile;
use App\Enums\OptimizeOutcome;
use App\Jobs\OptimizeExistingMediaBatch;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    config(['media.optimize.enabled' => true, 'queue.default' => 'sync']);
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit'])->create();
    $this->actingAs($this->actor);
});

/**
 * A candidate image with a real file on the faked disk.
 */
function pageCandidate(): Media
{
    $contents = makeImage('jpg', 400, 300);
    $media = Media::factory()->create(['mime' => 'image/jpeg', 'optimized_with' => null]);
    $media->forceFill(['path' => "media/2025/01/{$media->key}.jpg", 'name' => "{$media->key}.jpg", 'size' => strlen($contents)])->save();
    Storage::disk('local')->put($media->path, $contents);

    return $media;
}

it('is closed to guests and to users without media.edit', function () {
    auth()->logout();
    $this->get(route('admin.media.optimize'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $this->get(route('admin.media.optimize'))->assertForbidden();
});

it('shows the candidate count and a start button', function () {
    pageCandidate();
    pageCandidate();

    $this->get(route('admin.media.optimize'))
        ->assertOk()
        ->assertSee(trans_choice('media.optimize.candidates', 2, ['count' => 2]))
        ->assertSee(__('media.optimize.start'));
});

it('links to the media settings when optimization is off', function () {
    config(['media.optimize.enabled' => false]);

    $this->get(route('admin.media.optimize'))
        ->assertOk()
        ->assertSee(__('media.optimize.disabled'))
        ->assertSee(route('admin.settings.edit', ['tab' => 'media']), escape: false);
});

it('processes files in the browser when there is no queue', function () {
    $media = pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->assertSee(__('media.optimize.status.running'))
        ->call('tick')
        ->assertSee(__('media.optimize.status.done'));

    expect(app(OptimizationRunStore::class)->get()->driver)->toBe('browser')
        ->and($media->refresh()->optimized_with)->not->toBeNull();
});

it('dispatches the job and leaves processing to the queue', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $media = pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->call('tick')
        ->assertSet('browserFallback', false)
        ->assertDontSee(__('media.optimize.fallback'))
        ->assertSee(__('media.optimize.tab_open_queue'));

    Queue::assertPushed(OptimizeExistingMediaBatch::class, 1);
    Queue::assertPushed(OptimizeExistingMediaBatch::class, fn (OptimizeExistingMediaBatch $job) => $job->runId === app(OptimizationRunStore::class)->get()->id);
    expect($media->refresh()->optimized_with)->toBeNull();
});

it('continues in the browser when the queue does not pick the run up', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $media = pageCandidate();

    $component = Livewire::test('pages::admin.media.optimize')->call('start');

    $this->travel(31)->seconds();

    $component->call('tick')
        ->assertSet('browserFallback', true)
        ->assertSee(__('media.optimize.status.done'));

    expect($media->refresh()->optimized_with)->not->toBeNull();
});

it('does not fall back while a queue batch holds the lock', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $media = pageCandidate();

    $component = Livewire::test('pages::admin.media.optimize')->call('start');
    $this->travel(31)->seconds();
    $lock = Cache::lock(OptimizeExistingMedia::LOCK, 60);
    $lock->get();

    $component->call('tick')
        ->assertSet('browserFallback', false)
        ->assertDontSee(__('media.optimize.fallback'));

    $lock->release();
    expect($media->refresh()->optimized_with)->toBeNull();
});

it('explains the fallback while the run is still going', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    foreach (range(1, 2) as $i) {
        pageCandidate();
    }

    // One file per batch, so the run is still going after the tick that falls back.
    app()->instance(OptimizeExistingMedia::class, new class(app(OptimizeImage::class), app(RebuildMediaFile::class), app(OptimizationRunStore::class)) extends OptimizeExistingMedia
    {
        public function processBatch(string $driver, float $budgetSeconds): ?OptimizationRun
        {
            return parent::processBatch($driver, 0.0);
        }
    });

    $component = Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->call('tick')
        ->assertDontSee(__('media.optimize.fallback'))
        ->assertSee(__('media.optimize.tab_open_queue'));

    $this->travel(31)->seconds();

    $component->call('tick')
        ->assertSet('browserFallback', true)
        ->assertSee(__('media.optimize.status.running'))
        ->assertSee(__('media.optimize.fallback'))
        ->assertSee(__('media.optimize.tab_open_browser'))
        ->assertDontSee(__('media.optimize.tab_open_queue'));

    expect(app(OptimizationRunStore::class)->get()->driver)->toBe('browser')
        ->and(app(OptimizationRunStore::class)->get()->processed())->toBe(1);
});

it('does not force the browser mode on a reload when a queue is available', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    pageCandidate();
    app(OptimizeExistingMedia::class)->startWithoutActor();
    $run = app(OptimizationRunStore::class)->get();
    $run->driver = 'browser';
    app(OptimizationRunStore::class)->save($run);

    Livewire::test('pages::admin.media.optimize')->assertSet('browserFallback', false);
});

it('shows a toast when optimization is off', function () {
    config(['media.optimize.enabled' => false]);

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->assertDispatched('toast-show');

    expect(app(OptimizationRunStore::class)->get())->toBeNull();
});

it('refuses every action once media.edit is gone', function (string $action) {
    pageCandidate();
    $engine = app(OptimizeExistingMedia::class);
    $engine->startWithoutActor();
    $before = app(OptimizationRunStore::class)->get()->toArray();

    $component = Livewire::test('pages::admin.media.optimize');
    $this->actor->syncPermissions(['admin.access', 'media.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actor->refresh();

    $component->call($action)->assertForbidden();

    expect(app(OptimizationRunStore::class)->get()?->toArray())->toBe($before);
})->with(['start', 'startOver', 'pause', 'resume', 'stop', 'tick']);

it('pauses and resumes', function () {
    pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->call('pause')
        ->assertSee(__('media.optimize.resume'))
        ->call('tick');

    expect(app(OptimizationRunStore::class)->get()->processed())->toBe(0);

    Livewire::test('pages::admin.media.optimize')
        ->call('resume')
        ->assertSee(__('media.optimize.status.running'));
});

it('offers to start over when the settings changed', function () {
    pageCandidate();
    Livewire::test('pages::admin.media.optimize')->call('start');

    config(['media.optimize.quality' => 61]);

    Livewire::test('pages::admin.media.optimize')
        ->assertSee(__('media.optimize.stale_heading'))
        ->assertSee(__('media.optimize.start_over'))
        ->call('startOver')
        ->assertDontSee(__('media.optimize.stale_heading'));
});

it('stops the run and clears its progress', function () {
    pageCandidate();

    Livewire::test('pages::admin.media.optimize')->call('start')->call('stop');

    expect(app(OptimizationRunStore::class)->get())->toBeNull();
});

it('offers a new run after one is done when files were skipped', function () {
    $media = pageCandidate();
    Storage::disk('local')->delete($media->path);

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->call('tick')
        ->assertSee(__('media.optimize.done_heading'))
        ->assertSee(__('media.optimize.start_new'));
});

it('does not start without media.edit', function () {
    pageCandidate();
    $viewer = User::factory()->withPermissions(['admin.access', 'media.view'])->create();

    Livewire::actingAs($viewer)->test('pages::admin.media.optimize')->assertForbidden();
});

it('asks before stopping', function () {
    pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->set('showStopModal', true)
        ->assertSee(__('media.optimize.stop_heading'));
});

it('polls with keep-alive while running, so a background tab keeps the run going', function () {
    pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->assertSeeHtml('wire:poll.2s.keep-alive="tick"')
        ->call('pause')
        ->assertDontSeeHtml('wire:poll');
});

it('does not show a candidate count while optimization is off', function () {
    pageCandidate();
    config(['media.optimize.enabled' => false]);

    $this->get(route('admin.media.optimize'))
        ->assertOk()
        ->assertDontSee(trans_choice('media.optimize.candidates', 1, ['count' => 1]))
        ->assertDontSee(__('media.optimize.none'));
});

it('pauses a running run once optimization is turned off', function (string $queue) {
    config(['queue.default' => $queue]);
    Queue::fake();
    pageCandidate();
    $component = Livewire::test('pages::admin.media.optimize')->call('start');

    config(['media.optimize.enabled' => false]);

    $component->call('tick')->assertSee(__('media.optimize.status.paused'));

    expect(app(OptimizationRunStore::class)->get()->status)->toBe('paused');
})->with(['sync', 'database']);

it('pluralizes the progress by the total', function (string $locale) {
    app()->setLocale($locale);
    config(['queue.default' => 'database']);
    Queue::fake();
    pageCandidate();

    Livewire::test('pages::admin.media.optimize')
        ->call('start')
        ->assertSee(trans_choice('media.optimize.progress', 1, ['processed' => 0, 'total' => 1]));

    expect(trans_choice('media.optimize.progress', 1, ['processed' => 0, 'total' => 1]))
        ->toBe($locale === 'uk' ? '0 з 1 файлу' : '0 of 1 file');
})->with(['en', 'uk']);

it('has a label for every outcome and a skipped reason for every outcome but optimized', function (string $locale) {
    app()->setLocale($locale);

    foreach (OptimizeOutcome::cases() as $outcome) {
        expect(__("media.optimize.outcomes.{$outcome->value}"))->not->toBe("media.optimize.outcomes.{$outcome->value}");

        if ($outcome !== OptimizeOutcome::Optimized) {
            expect(trans_choice("media.optimize.skipped_reasons.{$outcome->value}", 2, ['count' => 2]))->not->toContain('skipped_reasons');
        }
    }
})->with(['en', 'uk']);

it('lists a file changed during the run as skipped', function () {
    pageCandidate();
    $run = app(OptimizeExistingMedia::class)->startWithoutActor();
    $run->record(Media::query()->firstOrFail(), OptimizeOutcome::Changed, 10, 10);
    app(OptimizationRunStore::class)->save($run);

    Livewire::test('pages::admin.media.optimize')
        ->assertSee(trans_choice('media.optimize.skipped_reasons.changed', 1, ['count' => 1]))
        ->assertSee(__('media.optimize.outcomes.changed'));
});
