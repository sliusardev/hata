<?php

use App\Models\User;
use Hata\Actions\Media\DeleteMedia;
use Hata\Actions\Media\PruneMediaDeletions;
use Hata\Actions\Media\RestoreOriginal;
use Hata\Actions\Media\UploadMedia;
use Hata\Exceptions\MediaUploadException;
use Hata\Models\Media;
use Hata\Models\MediaPendingDeletion;
use Hata\Support\Media\MediaSource;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\RecordingDisk;

beforeEach(function () {
    usePublicMediaDisk();
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.upload', 'media.delete', 'media.edit'])->create();
});

function uploadPublicPhoto(User $actor): Media
{
    return app(UploadMedia::class)->handle($actor, uploadedImage('photo.jpg', makeImage('jpg', 1200, 800)));
}

it('stores the file and thumbnail on the public disk and the original on the private one', function () {
    $media = uploadPublicPhoto($this->actor);

    expect($media->disk)->toBe('public')
        ->and($media->originalsDisk())->toBe('local')
        ->and($media->path)->toContain('-v1')
        ->and($media->thumb_path)->toContain('-v1')
        ->and($media->original_path)->toContain('-v1');
    Storage::disk('public')->assertExists([$media->path, (string) $media->thumb_path]);
    Storage::disk('public')->assertMissing((string) $media->original_path);
    Storage::disk('local')->assertExists((string) $media->original_path);
    Storage::disk('local')->assertMissing($media->path);
});

it('keeps svg and documents on the private disk', function (string $name, string $bytes) {
    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage($name, $bytes));

    expect($media->disk)->toBe('local')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Storage::disk('local')->assertExists($media->path);
})->with([
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>'],
    'pdf' => ['report.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"],
]);

it('deletes every object of a public row from its own disk', function () {
    $media = uploadPublicPhoto($this->actor);

    app(DeleteMedia::class)->handle($this->actor, $media);

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('reads the source and restores from the original on the private disk', function () {
    $media = uploadPublicPhoto($this->actor);
    $original = (string) Storage::disk('local')->get((string) $media->original_path);

    $source = MediaSource::for($media);
    $restored = app(RestoreOriginal::class)->handle($this->actor, $media);

    expect($source->isOriginal)->toBeTrue()
        ->and($source->contents)->toBe($original)
        ->and($restored->version)->toBe(2);
});

it('leaves no object on either disk when a write fails', function () {
    $fake = Storage::disk('public');
    $failing = new RecordingDisk($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
    $failing->beforeWrite = function (string $path) use ($failing): void {
        $failing->failWrites = str_contains($path, 'thumbs');
    };
    Storage::set('public', $failing);

    expect(fn () => uploadPublicPhoto($this->actor))->toThrow(MediaUploadException::class)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Media::query()->count())->toBe(0);
});

it('does not prune an original on the private disk that a public row still uses', function () {
    $media = uploadPublicPhoto($this->actor);
    MediaPendingDeletion::query()->create(['disk' => 'local', 'path' => (string) $media->original_path, 'delete_after' => now()->subDay()]);

    app(PruneMediaDeletions::class)->handle();

    Storage::disk('local')->assertExists((string) $media->original_path);
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});
