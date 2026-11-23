<?php

use App\Models\User;
use Hata\Actions\Media\OptimizeExistingMedia;
use Hata\Actions\Media\PruneMediaDeletions;
use Hata\Models\Media;
use Hata\Models\MediaPendingDeletion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->disk = fakeCloudDisk();
});

/**
 * An object on the faked cloud disk with a pending deletion due after the given number of days (negative: overdue).
 */
function pendingObject(string $path, int $dueInDays): MediaPendingDeletion
{
    Storage::disk('s3')->put($path, 'old');

    return MediaPendingDeletion::query()->create(['disk' => 's3', 'path' => $path, 'delete_after' => now()->addDays($dueInDays)]);
}

it('deletes the objects and rows that are due and keeps the rest', function () {
    pendingObject('media/2026/11/a-v1.webp', -1);
    pendingObject('media/thumbs/a-v1.webp', 0);
    pendingObject('media/2026/11/b-v1.webp', 3);
    MediaPendingDeletion::query()->create(['disk' => 's3', 'path' => 'media/thumbs/gone-v1.webp', 'delete_after' => now()->subDay()]);

    expect(app(PruneMediaDeletions::class)->handle())->toBe(3);

    $this->disk->assertMissing(['media/2026/11/a-v1.webp', 'media/thumbs/a-v1.webp']);
    $this->disk->assertExists('media/2026/11/b-v1.webp');
    expect(MediaPendingDeletion::query()->pluck('path')->all())->toBe(['media/2026/11/b-v1.webp']);
});

it('deletes at most the given number of rows, oldest first', function () {
    pendingObject('media/2026/11/old-v1.webp', -3);
    pendingObject('media/2026/11/new-v1.webp', -1);

    expect(app(PruneMediaDeletions::class)->handle(1))->toBe(1);

    $this->disk->assertMissing('media/2026/11/old-v1.webp');
    $this->disk->assertExists('media/2026/11/new-v1.webp');
});

it('never deletes an object a media row still uses', function () {
    $media = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/used-v2.webp']);
    pendingObject($media->path, -1);

    expect(app(PruneMediaDeletions::class)->handle())->toBe(1);

    $this->disk->assertExists($media->path);
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});

it('prunes from the scheduled command', function () {
    pendingObject('media/2026/11/a-v1.webp', -1);

    $this->artisan('media:prune-deleted')->assertSuccessful();

    $this->disk->assertMissing('media/2026/11/a-v1.webp');

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'media:prune-deleted'));
    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

it('prunes at the end of an optimize batch', function () {
    Storage::fake('local');
    pendingObject('media/2026/11/a-v1.webp', -1);
    $media = Media::factory()->create(['disk' => 'local', 'mime' => 'image/jpeg', 'optimized_with' => null, 'path' => 'media/2025/01/x.jpg']);
    Storage::disk('local')->put($media->path, makeImage('jpg'));
    $engine = app(OptimizeExistingMedia::class);
    $engine->startWithoutActor();

    $engine->processBatch('cli', 5.0);

    $this->disk->assertMissing('media/2026/11/a-v1.webp');
});

it('prunes from the library page at most once an hour', function () {
    seedRolesAndPermissions();
    $user = User::factory()->withPermissions(['admin.access', 'media.view'])->create();
    Cache::flush();
    pendingObject('media/2026/11/a-v1.webp', -1);

    $this->actingAs($user)->get(route('admin.media.index'))->assertOk();

    $this->disk->assertMissing('media/2026/11/a-v1.webp');

    pendingObject('media/2026/11/b-v1.webp', -1);
    $this->actingAs($user)->get(route('admin.media.index'))->assertOk();

    $this->disk->assertExists('media/2026/11/b-v1.webp');

    $this->travel(61)->minutes();
    $this->actingAs($user)->get(route('admin.media.index'))->assertOk();

    $this->disk->assertMissing('media/2026/11/b-v1.webp');
});

it('skips the prune when the optimize batch already spent its budget', function () {
    Storage::fake('local');
    pendingObject('media/2026/11/a-v1.webp', -1);
    $media = Media::factory()->create(['disk' => 'local', 'mime' => 'image/jpeg', 'optimized_with' => null, 'path' => 'media/2025/01/x.jpg']);
    Storage::disk('local')->put($media->path, makeImage('jpg'));
    $engine = app(OptimizeExistingMedia::class);
    $engine->startWithoutActor();

    $engine->processBatch('browser', 0.0);

    $this->disk->assertExists('media/2026/11/a-v1.webp');
});

it('keeps the rows and tries again later when the disk does not delete the objects', function () {
    $pending = pendingObject('media/2026/11/stuck-v1.webp', -1);
    $this->disk->failDeletes = true;

    expect(app(PruneMediaDeletions::class)->handle())->toBe(0);

    $this->disk->assertExists('media/2026/11/stuck-v1.webp');
    expect($pending->fresh()->delete_after->isAfter(now()->addHours(23)))->toBeTrue();
});
