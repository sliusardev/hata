<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Jobs\OptimizeExistingMediaBatch;
use App\Models\Media;
use App\Models\User;
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

    $component = Livewire::test('pages::admin.media.optimize')->call('start');
    $this->travel(31)->seconds();

    // The batch budget is exhausted after the first file, so the run is still going.
    config(['queue.default' => 'database']);
    $component->call('tick')->assertSet('browserFallback', true);

    expect(app(OptimizationRunStore::class)->get()->driver)->toBe('browser');
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
