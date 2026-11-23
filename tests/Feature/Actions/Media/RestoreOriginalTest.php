<?php

use App\Models\User;
use Hata\Actions\Media\CropMedia;
use Hata\Actions\Media\RestoreOriginal;
use Hata\Models\Media;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

function restorableMedia(?string $original): Media
{
    $media = Media::factory()->create();
    $media->forceFill([
        'original_path' => $original === null ? null : "media/originals/{$media->key}.jpg",
        'thumb_path' => "media/thumbs/{$media->key}.webp",
    ])->save();
    Storage::disk('local')->put($media->path, makeImage('webp', 2560, 1707));

    if ($original !== null) {
        Storage::disk('local')->put((string) $media->original_path, $original);
    }

    return $media;
}

it('restores the original and clears the edits', function () {
    $media = restorableMedia(makeImage('jpg', 3000, 2000));
    $cropped = app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]);
    expect($cropped->edits)->not->toBeNull();

    $restored = app(RestoreOriginal::class)->handle($this->actor, $cropped);
    $image = Image::fromBytes((string) Storage::disk('local')->get($restored->path));

    expect($restored->edits)->toBeNull()
        ->and($restored->version)->toBe(3)
        ->and([$image->width(), $image->height()])->toBe([2560, 1707]);
});

it('refuses to restore without an original', function (?string $original) {
    $media = restorableMedia($original);

    if ($original === null) {
        $media->forceFill(['original_path' => 'media/originals/missing.jpg'])->save();
    }

    try {
        app(RestoreOriginal::class)->handle($this->actor, $media);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors()['original'])->toBe([__('hata::media.errors.no_original')]);
    }
})->with([null]);

it('refuses to restore when original_path is empty', function () {
    $media = restorableMedia(null);

    expect(fn () => app(RestoreOriginal::class)->handle($this->actor, $media))
        ->toThrow(ValidationException::class);
});

it('requires media.edit to restore', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view'])->create();

    expect(fn () => app(RestoreOriginal::class)->handle($actor, restorableMedia(makeImage('jpg', 100, 100))))
        ->toThrow(AuthorizationException::class);
});
