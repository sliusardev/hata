<?php

use App\Models\User;
use Hata\Actions\Media\DeleteMedia;
use Hata\Actions\Media\MoveMedia;
use Hata\Actions\Media\UpdateMedia;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaPendingDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit', 'media.delete'])->create();
});

it('updates title, alt and folder', function () {
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();

    $result = app(UpdateMedia::class)->handle($this->actor, $media, [
        'title' => 'Sunset',
        'alt' => ['en' => 'A sunset', 'uk' => 'Захід сонця'],
        'folder_id' => $folder->id,
    ]);

    expect($result->title)->toBe('Sunset')
        ->and($result->alt)->toBe(['en' => 'A sunset', 'uk' => 'Захід сонця'])
        ->and($result->folder_id)->toBe($folder->id);
});

it('ignores alt for locales that are not available', function () {
    $media = Media::factory()->create();

    $result = app(UpdateMedia::class)->handle($this->actor, $media, ['alt' => ['en' => 'Hi', 'de' => 'Hallo']]);

    expect($result->alt)->toBe(['en' => 'Hi']);
});

it('rejects a non-array alt with a validation error', function () {
    app(UpdateMedia::class)->handle($this->actor, Media::factory()->create(), ['alt' => 'text']);
})->throws(ValidationException::class);

it('stores an empty title as null', function () {
    $media = Media::factory()->create(['title' => 'Old']);

    expect(app(UpdateMedia::class)->handle($this->actor, $media, ['title' => '  ']))->title->toBeNull();
});

it('trims alt text, so whitespace-only alt is stored empty', function () {
    $media = Media::factory()->create();

    $result = app(UpdateMedia::class)->handle($this->actor, $media, ['alt' => ['en' => "  A sunset \n", 'uk' => '   ']]);

    expect($result->refresh()->alt)->toBe(['en' => 'A sunset', 'uk' => '']);
});

it('requires media.edit to update', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view'])->create();

    app(UpdateMedia::class)->handle($actor, Media::factory()->create(), ['title' => 'x']);
})->throws(AuthorizationException::class);

it('moves several files to a folder and back to no folder', function () {
    $folder = MediaFolder::factory()->create();
    $files = Media::factory()->count(3)->create();

    app(MoveMedia::class)->handle($this->actor, $files, $folder);
    expect(Media::query()->where('folder_id', $folder->id)->count())->toBe(3);

    app(MoveMedia::class)->handle($this->actor, $files, null);
    expect(Media::query()->whereNotNull('folder_id')->count())->toBe(0);
});

it('deletes files and their stored files after commit', function () {
    $media = mediaWithFiles();
    $paths = [$media->path, $media->original_path, $media->thumb_path];

    $count = app(DeleteMedia::class)->handle($this->actor, $media);

    expect($count)->toBe(1)
        ->and(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($paths);
});

it('deletes a media row whose files are already gone', function () {
    $media = Media::factory()->create(['original_path' => 'media/originals/gone.jpg', 'thumb_path' => 'media/thumbs/gone.webp']);

    expect(app(DeleteMedia::class)->handle($this->actor, [$media]))->toBe(1)
        ->and(Media::query()->count())->toBe(0);
});

it('requires media.delete to delete', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
    $media = mediaWithFiles();

    expect(fn () => app(DeleteMedia::class)->handle($actor, $media))->toThrow(AuthorizationException::class);

    expect(Media::query()->count())->toBe(1);
    Storage::disk('local')->assertExists($media->path);
});

it('keeps the files when an enclosing transaction rolls back', function () {
    $media = mediaWithFiles();
    $paths = [$media->path, $media->original_path, $media->thumb_path];

    try {
        DB::transaction(function () use ($media) {
            app(DeleteMedia::class)->handle($this->actor, $media);

            Storage::disk('local')->assertExists($media->path);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(Media::query()->count())->toBe(1);
    Storage::disk('local')->assertExists($paths);
});

it('deletes the objects of a cloud file at once instead of deferring them', function () {
    $disk = fakeCloudDisk();
    $media = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/a-v3.webp', 'thumb_path' => 'media/thumbs/a-v3.webp']);
    $disk->put($media->path, 'data');
    $disk->put((string) $media->thumb_path, 'data');

    app(DeleteMedia::class)->handle($this->actor, $media);

    $disk->assertMissing([$media->path, (string) $media->thumb_path]);
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});
