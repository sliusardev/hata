<?php

use App\Actions\Media\MoveMediaToDisk;
use App\Jobs\MoveMediaBatch;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\DiskMoveRunStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    fakeCloudDisk('s3');
    seedRolesAndPermissions();
    config(['media.disk' => 's3', 'filesystems.disks.s3.bucket' => 'media', 'queue.default' => 'sync']);
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit'])->create();
    $this->actingAs($this->actor);
});

/**
 * A row on the local disk (not the configured one) with its file.
 */
function movePageCandidate(): Media
{
    $media = Media::factory()->create(['disk' => 'local', 'size' => 4]);
    Storage::disk('local')->put($media->path, 'data');

    return $media;
}

it('is closed to guests and to users without media.edit', function () {
    auth()->logout();
    $this->get(route('admin.media.move'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $this->get(route('admin.media.move'))->assertForbidden();
});

it('shows how many files are on the other disk and where they go', function () {
    movePageCandidate();
    movePageCandidate();

    $this->get(route('admin.media.move'))
        ->assertOk()
        ->assertSee(__('media.move.title'))
        ->assertSee(__('media.move.target', ['storage' => __('site_settings.media.storage.s3')]))
        ->assertSee(trans_choice('media.move.candidates', 2, ['count' => 2]))
        ->assertSee(__('media.move.start'))
        ->assertSee(__('media.move.large_files_note'));
});

it('counts only the files this server would place elsewhere and calls it this server', function () {
    config(['media.disk' => 'public']);
    movePageCandidate();
    Media::factory()->document()->create(['disk' => 'local']);
    Media::factory()->create(['disk' => 'local', 'mime' => 'image/svg+xml', 'path' => 'media/x.svg']);
    Media::factory()->create(['disk' => 'public']);
    Media::factory()->document()->create(['disk' => 's3']);

    $this->get(route('admin.media.move'))
        ->assertOk()
        ->assertSee(__('media.move.target', ['storage' => __('site_settings.media.storage.public')]))
        ->assertSee(trans_choice('media.move.candidates', 2, ['count' => 2]));
});

it('says when nothing is left to move', function () {
    $this->get(route('admin.media.move'))->assertOk()->assertSee(__('media.move.none'));
});

it('moves files in the browser when there is no queue', function () {
    $media = movePageCandidate();

    Livewire::test('pages::admin.media.move')
        ->call('start')
        ->assertSee(__('media.move.status.running'))
        ->call('tick')
        ->assertSee(__('media.move.status.done'))
        ->assertSee(__('media.move.outcomes.moved'));

    expect(app(DiskMoveRunStore::class)->get()->driver)->toBe('browser')
        ->and($media->fresh()->disk)->toBe('s3');
});

it('dispatches the job and leaves the move to the queue', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $media = movePageCandidate();

    Livewire::test('pages::admin.media.move')
        ->call('start')
        ->call('tick')
        ->assertSet('browserFallback', false)
        ->assertSee(__('media.batch.tab_open_queue'));

    Queue::assertPushed(MoveMediaBatch::class, fn (MoveMediaBatch $job) => $job->runId === app(DiskMoveRunStore::class)->get()->id);
    expect($media->fresh()->disk)->toBe('local');
});

it('continues in the browser when the queue does not pick the move up, unless a batch holds the lock', function () {
    config(['queue.default' => 'database']);
    Queue::fake();
    $media = movePageCandidate();

    $component = Livewire::test('pages::admin.media.move')->call('start');
    $this->travel(31)->seconds();

    $lock = Cache::lock(MoveMediaToDisk::LOCK, 60);
    $lock->get();
    $component->call('tick')->assertSet('browserFallback', false);
    $lock->release();

    $component->call('tick')
        ->assertSet('browserFallback', true)
        ->assertSee(__('media.move.status.done'));

    expect($media->fresh()->disk)->toBe('s3');
});

it('pauses, resumes and stops the move', function () {
    movePageCandidate();

    $component = Livewire::test('pages::admin.media.move')
        ->call('start')
        ->call('pause')
        ->assertSee(__('media.batch.resume'));

    expect(app(DiskMoveRunStore::class)->get()->status)->toBe('paused');

    $component->call('resume')->call('stop');

    expect(app(DiskMoveRunStore::class)->get())->toBeNull();
});

it('lists failed files after the run', function () {
    $media = movePageCandidate();
    Storage::disk('local')->delete($media->path);

    Livewire::test('pages::admin.media.move')
        ->call('start')
        ->call('tick')
        ->assertSee(__('media.move.outcomes.missing'))
        ->assertSee(trans_choice('media.move.done_left', 1, ['count' => 1]));
});

it('offers a new move to the configured storage after a finished move to the other one', function () {
    config(['media.disk' => 'public']);
    $media = Media::factory()->create(['disk' => 's3', 'size' => 4]);
    Storage::disk('s3')->put($media->path, 'data');

    $component = Livewire::test('pages::admin.media.move')->call('start')->call('tick')
        ->assertSee(__('media.move.status.done'));
    expect($media->fresh()->disk)->toBe('public');

    // The admin switched the storage back afterwards: what is left and a new move both concern that storage.
    config(['media.disk' => 's3']);

    $component->call('$refresh')
        ->assertSee(trans_choice('media.move.done_left', 1, ['count' => 1]))
        ->assertSeeHtml('data-test="move-start-new"')
        ->call('start');

    expect(app(DiskMoveRunStore::class)->get()->target)->toBe('s3');
});

it('offers no move while the private local mode from .env is in effect', function () {
    config(['media.disk' => 'local']);
    Media::factory()->create(['disk' => 's3']);
    Media::factory()->create(['disk' => 'public']);

    $this->get(route('admin.media.move'))
        ->assertOk()
        ->assertSee(__('media.move.none'));

    Livewire::test('pages::admin.media.move')
        ->assertSet('candidateCount', 0)
        ->call('start');

    expect(app(DiskMoveRunStore::class)->get())->toBeNull();
});
