<?php

use App\Models\User;
use Hata\Actions\Media\CropMedia;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaPendingDeletion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit', 'media.upload'])->create();
});

/**
 * @param  array<string, mixed>  $crop
 * @return array<string, mixed>
 */
function cropInput(array $crop = ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5], array $extra = []): array
{
    return ['crop' => $crop, 'rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'size' => null, ...$extra];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cropMedia(string $current, ?string $original = null, array $attributes = []): Media
{
    $media = Media::factory()->create($attributes);
    $media->forceFill([
        'original_path' => $original === null ? null : "media/originals/{$media->key}.jpg",
        'thumb_path' => "media/thumbs/{$media->key}.webp",
    ])->save();

    $disk = Storage::disk('local');
    $disk->put($media->path, $current);
    $disk->put((string) $media->thumb_path, 'old-thumb');

    if ($original !== null) {
        $disk->put((string) $media->original_path, $original);
    }

    return $media;
}

/**
 * @return array{int, int}
 */
function storedSize(Media $media): array
{
    $image = Image::fromBytes((string) Storage::disk('local')->get($media->path));

    return [$image->width(), $image->height()];
}

it('crops from the kept original and stores the edits', function () {
    $media = cropMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3000, 2000));

    $result = app(CropMedia::class)->handle($this->actor, $media, cropInput());

    expect($result->version)->toBe(2)
        ->and($result->edits['crop'])->toEqual(['x' => 0, 'y' => 0, 'w' => 1500, 'h' => 1000])
        ->and(storedSize($result))->toBe([1500, 1000]);
});

it('replaces earlier edits instead of stacking them', function () {
    $media = cropMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3000, 2000));
    $action = app(CropMedia::class);

    $action->handle($this->actor, $media, cropInput());
    $result = $action->handle($this->actor, $media->fresh(), cropInput(['x' => 0.5, 'y' => 0.5, 'w' => 0.5, 'h' => 0.5]));

    expect($result->edits['crop'])->toEqual(['x' => 1500, 'y' => 1000, 'w' => 1500, 'h' => 1000])
        ->and(storedSize($result))->toBe([1500, 1000]);
});

it('bakes the crop in when no original is kept', function () {
    $media = cropMedia(makeImage('webp', 2000, 1000));
    $action = app(CropMedia::class);

    $first = $action->handle($this->actor, $media, cropInput(['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 1]));

    expect($first->edits)->toBeNull()
        ->and(storedSize($first))->toBe([1000, 1000]);

    $second = $action->handle($this->actor, $first->fresh(), cropInput(['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]));

    expect($second->edits)->toBeNull()
        ->and(storedSize($second))->toBe([500, 500]);
});

it('converts the fractions in the rotated frame', function () {
    $media = cropMedia(makeImage('webp', 2000, 1000), makeImage('jpg', 2000, 1000));

    $result = app(CropMedia::class)->handle($this->actor, $media, cropInput(['x' => 0, 'y' => 0, 'w' => 1, 'h' => 0.5], ['rotate' => 90]));

    expect($result->edits['crop'])->toEqual(['x' => 0, 'y' => 0, 'w' => 1000, 'h' => 1000])
        ->and($result->edits['rotate'])->toBe(90);
});

it('refuses to crop an animated gif or an svg', function (string $kind) {
    $media = $kind === 'gif'
        ? cropMedia(animatedGif(), null, ['mime' => 'image/gif', 'name' => 'a.gif'])
        : cropMedia('<svg xmlns="http://www.w3.org/2000/svg"/>', null, ['mime' => 'image/svg+xml', 'name' => 'a.svg']);

    try {
        app(CropMedia::class)->handle($this->actor, $media, cropInput());
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['crop'])->toBe([__('hata::media.errors.not_editable')]);
    }

    expect($media->fresh()->version)->toBe(1);
})->with(['gif', 'svg']);

it('rejects fractions outside the image', function (array $crop) {
    $media = cropMedia(makeImage('webp', 800, 600), makeImage('jpg', 800, 600));

    expect(fn () => app(CropMedia::class)->handle($this->actor, $media, cropInput($crop)))
        ->toThrow(ValidationException::class);

    expect($media->fresh()->version)->toBe(1);
})->with([
    'x too big' => [['x' => 1, 'y' => 0, 'w' => 0.5, 'h' => 0.5]],
    'negative x' => [['x' => -0.1, 'y' => 0, 'w' => 0.5, 'h' => 0.5]],
    'zero width' => [['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0.5]],
    'overflow right' => [['x' => 0.6, 'y' => 0, 'w' => 0.5, 'h' => 0.5]],
    'overflow bottom' => [['x' => 0, 'y' => 0.9, 'w' => 0.5, 'h' => 0.5]],
    'missing key' => [['x' => 0, 'y' => 0, 'w' => 0.5]],
]);

it('rejects a bad rotation or size', function (array $extra, string $field) {
    $media = cropMedia(makeImage('webp', 800, 600), makeImage('jpg', 800, 600));

    try {
        app(CropMedia::class)->handle($this->actor, $media, cropInput(extra: $extra));
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'rotation' => [['rotate' => 45], 'rotate'],
    'size' => [['size' => ['w' => 0, 'h' => 10]], 'size'],
]);

it('saves a cropped copy in the same folder and leaves the source alone', function () {
    $folder = MediaFolder::factory()->create();
    $media = cropMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3000, 2000), ['folder_id' => $folder->id, 'name' => 'photo.webp']);
    $before = Storage::disk('local')->get($media->path);

    $copy = app(CropMedia::class)->handle($this->actor, $media, cropInput(), asCopy: true);

    expect($copy->id)->not->toBe($media->id)
        ->and($copy->key)->not->toBe($media->key)
        ->and($copy->folder_id)->toBe($folder->id)
        ->and($copy->uploaded_by)->toBe($this->actor->id)
        ->and($copy->name)->toBe('photo-2.webp')
        ->and($copy->edits['crop'])->toEqual(['x' => 0, 'y' => 0, 'w' => 1500, 'h' => 1000])
        ->and($copy->original_path)->not->toBeNull()->not->toBe($media->original_path)
        ->and(storedSize($copy))->toBe([1500, 1000]);

    Storage::disk('local')->assertExists((string) $copy->original_path);
    Storage::disk('local')->assertExists((string) $copy->thumb_path);

    $media->refresh();
    expect($media->version)->toBe(1)
        ->and($media->edits)->toBeNull()
        ->and(Storage::disk('local')->get($media->path))->toBe($before);
});

it('saves a baked-in copy when no original is kept', function () {
    $media = cropMedia(makeImage('webp', 2000, 1000), null, ['name' => 'photo.webp']);

    $copy = app(CropMedia::class)->handle($this->actor, $media, cropInput(), asCopy: true);

    expect($copy->edits)->toBeNull()
        ->and($copy->original_path)->toBeNull()
        ->and(storedSize($copy))->toBe([1000, 500]);
});

it('requires media.upload to save a copy', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
    $media = cropMedia(makeImage('webp', 800, 600), makeImage('jpg', 800, 600));

    expect(fn () => app(CropMedia::class)->handle($actor, $media, cropInput(), asCopy: true))
        ->toThrow(AuthorizationException::class);

    expect(Media::count())->toBe(1);
});

it('requires media.edit to crop in place', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.upload'])->create();
    $media = cropMedia(makeImage('webp', 800, 600), makeImage('jpg', 800, 600));

    expect(fn () => app(CropMedia::class)->handle($actor, $media, cropInput()))
        ->toThrow(AuthorizationException::class);
});

it('keeps the old cloud objects of a cropped file for 7 days while new renders point at the new ones', function () {
    $this->freezeSecond();
    $disk = fakeCloudDisk();
    $media = Media::factory()->create(['disk' => 's3', 'width' => 2560, 'height' => 1707]);
    $media->forceFill([
        'path' => "media/2026/10/{$media->key}-v1.webp",
        'thumb_path' => "media/thumbs/{$media->key}-v1.webp",
        'original_path' => "media/originals/{$media->key}-v1.jpg",
    ])->save();
    $oldFile = makeImage('webp', 2560, 1707);
    $disk->put($media->path, $oldFile);
    $disk->put((string) $media->thumb_path, 'old-thumb');
    $disk->put((string) $media->original_path, makeImage('jpg', 3000, 2000));
    [$oldPath, $oldThumb] = [$media->path, (string) $media->thumb_path];

    $result = app(CropMedia::class)->handle($this->actor, $media, cropInput());

    // A page rendered before the crop still gets the old image.
    expect($disk->get($oldPath))->toBe($oldFile)
        ->and($disk->get($oldThumb))->toBe('old-thumb')
        // A page rendered now points at the new objects.
        ->and($result->fresh()->path)->toBe('media/'.now()->format('Y/m')."/{$media->key}-v2.webp")
        ->and($result->thumb_path)->toBe("media/thumbs/{$media->key}-v2.webp")
        ->and(Image::fromBytes((string) $disk->get($result->path))->width())->toBe(1500)
        ->and(MediaPendingDeletion::query()->where('delete_after', now()->addDays(7))->pluck('path')->sort()->values()->all())
        ->toBe([$oldPath, $oldThumb]);
});
