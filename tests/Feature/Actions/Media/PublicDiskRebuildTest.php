<?php

use App\Actions\Media\CropMedia;
use App\Actions\Media\ReplaceMediaFile;
use App\Actions\Media\UploadMedia;
use App\Exceptions\MediaWriteException;
use App\Models\Media;
use App\Models\MediaPendingDeletion;
use App\Models\User;
use App\Support\Media\MediaPaths;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    usePublicMediaDisk();
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.upload', 'media.edit'])->create();
});

/**
 * A raster image uploaded to the public disk: its `-v1` file and thumbnail there, its `-v1` original on the private disk.
 */
function publicRebuildPhoto(User $actor): Media
{
    return app(UploadMedia::class)->handle($actor, uploadedImage('photo.jpg', makeImage('jpg', 1200, 800)));
}

/**
 * The pending deletions as [disk, path] pairs, sorted by path.
 *
 * @return list<array{0: string, 1: string}>
 */
function pendingDeletionPairs(): array
{
    return MediaPendingDeletion::query()->orderBy('path')->get()
        ->map(fn (MediaPendingDeletion $row): array => [$row->disk, $row->path])
        ->values()
        ->all();
}

it('crops a public row into new versioned files and keeps the replaced ones for 7 days', function () {
    $this->freezeSecond();
    $media = publicRebuildPhoto($this->actor);
    [$oldPath, $oldThumb, $original] = [$media->path, (string) $media->thumb_path, (string) $media->original_path];

    $cropped = app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5], 'rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'size' => null]);

    expect($cropped->version)->toBe(2)
        ->and($cropped->disk)->toBe('public')
        ->and($cropped->path)->toContain("{$media->key}-v2.")
        ->and($cropped->thumb_path)->toBe("media/thumbs/{$media->key}-v2.webp")
        ->and($cropped->original_path)->toBe($original);
    Storage::disk('public')->assertExists([$cropped->path, (string) $cropped->thumb_path, $oldPath, $oldThumb]);
    Storage::disk('local')->assertExists($original);
    expect(pendingDeletionPairs())->toBe([
        ['public', $oldPath],
        ['public', $oldThumb],
    ])
        ->and(MediaPendingDeletion::query()->get()->every(fn (MediaPendingDeletion $row) => $row->delete_after->equalTo(now()->addDays(7))))->toBeTrue();
});

it('keeps the new original of a replaced public row on the private disk and schedules the old one there', function () {
    $media = publicRebuildPhoto($this->actor);
    [$oldPath, $oldThumb, $oldOriginal] = [$media->path, (string) $media->thumb_path, (string) $media->original_path];

    $replaced = app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('new.png', makeImage('png', 900, 600)));

    expect($replaced->version)->toBe(2)
        ->and($replaced->original_path)->toBe("media/originals/{$media->key}-v2.png");
    Storage::disk('local')->assertExists([(string) $replaced->original_path, $oldOriginal]);
    Storage::disk('public')->assertMissing((string) $replaced->original_path);
    Storage::disk('public')->assertExists([$replaced->path, (string) $replaced->thumb_path]);
    expect(pendingDeletionPairs())->toBe([
        ['public', $oldPath],
        ['local', $oldOriginal],
        ['public', $oldThumb],
    ]);
});

it('removes its new files from both disks and keeps the old ones when saving fails', function () {
    $media = publicRebuildPhoto($this->actor);
    $before = $media->fresh()->getAttributes();
    $public = Storage::disk('public')->allFiles();
    $local = Storage::disk('local')->allFiles();

    Media::updating(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('new.png', makeImage('png'))))
        ->toThrow(RuntimeException::class, 'boom');

    expect($media->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toEqualCanonicalizing($public)
        ->and(Storage::disk('local')->allFiles())->toEqualCanonicalizing($local)
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
});

it('refuses to write an svg to a disk that publishes its files', function () {
    expect(fn () => MediaPaths::write(Storage::disk('public'), 'public', 'media/2026/11/x-v1.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>', 'image/svg+xml'))
        ->toThrow(MediaWriteException::class);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});
