<?php

use App\Actions\Media\CropMedia;
use App\Actions\Media\DeleteMedia;
use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\ReplaceMediaFile;
use App\Actions\Media\RestoreOriginal;
use App\Actions\Media\UploadMedia;
use App\Exceptions\MediaChangedException;
use App\Exceptions\MediaUploadException;
use App\Exceptions\MediaWriteException;
use App\Models\Media;
use App\Models\MediaPendingDeletion;
use App\Models\User;
use App\Support\Media\MediaPaths;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    config([
        'media.disk' => 's3',
        'filesystems.disks.s3.driver' => 's3',
        'filesystems.disks.s3.url' => 'https://cdn.test',
        'media.optimize.enabled' => true,
        'media.optimize.keep_originals' => true,
    ]);
    $cloud = fakeCloudDisk('s3');
    $cloud->buildTemporaryUrlsUsing(fn ($path) => 'https://signed.test/'.$path.'?sig=1');
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload', 'media.edit', 'media.delete'])->create();
});

function cloudUploadedPhoto(User $actor, string $name = 'photo.jpg', int $width = 3000, int $height = 2000): Media
{
    return app(UploadMedia::class)->handle($actor, uploadedImage($name, makeImage('jpg', $width, $height)));
}

it('uploads an image to the cloud disk with a thumbnail and direct urls', function () {
    $media = cloudUploadedPhoto($this->actor);

    expect($media->disk)->toBe('s3')
        ->and($media->path)->toContain('-v1')
        ->and($media->thumb_path)->not->toBeNull()
        ->and($media->url())->toBe(Storage::disk('s3')->url($media->path).'?v=1')
        ->and($media->thumbUrl())->toBe(Storage::disk('s3')->url($media->thumb_path).'?v=1')
        ->and($media->appUrl())->toContain('/media/'.$media->key)
        // Served straight from the bucket/CDN, never through the app route (`/media/{key}/{name}`).
        ->and($media->url())->not->toContain('/media/'.$media->key.'/')
        ->and($media->thumbUrl())->not->toContain('/media/'.$media->key.'/');
    $disk = Storage::disk('s3');
    $disk->assertExists($media->path);
    $disk->assertExists($media->thumb_path);
    $disk->assertExists($media->original_path);
    expect(Storage::disk('local')->exists($media->path))->toBeFalse();
});

it('uploads a document and serves it by a signed download url', function () {
    $file = UploadedFile::fake()->createWithContent('terms.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    $media = app(UploadMedia::class)->handle($this->actor, $file);

    expect($media->disk)->toBe('s3')->and($media->thumb_path)->toBeNull()
        ->and($media->url())->toBe(route('media.show', [$media->key, $media->name, 'v' => 1]));
    Storage::disk('s3')->assertExists($media->path);

    $this->actingAs($this->actor)->get($media->url())
        ->assertStatus(302)
        ->assertRedirect('https://signed.test/'.$media->path.'?sig=1');
});

it('crops to new versioned objects and schedules the old ones for deletion', function () {
    $media = cloudUploadedPhoto($this->actor);
    $old = [$media->path, $media->thumb_path];

    $cropped = app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]);

    expect($cropped->version)->toBe(2)
        ->and($cropped->path)->toContain('-v2')->not->toBe($old[0])
        ->and($cropped->thumb_path)->not->toBe($old[1]);
    $disk = Storage::disk('s3');
    $disk->assertExists($cropped->path);
    $disk->assertExists($cropped->thumb_path);
    // The old objects stay for pages rendered before, and are pending.
    $disk->assertExists($old[0]);
    expect(MediaPendingDeletion::query()->whereIn('path', $old)->count())->toBe(2)
        ->and($cropped->url())->toContain('?v=2');
});

it('replaces the file with a new version', function () {
    $media = cloudUploadedPhoto($this->actor);
    $before = $media->path;

    $replaced = app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('new.jpg', makeImage('jpg', 1200, 800)));

    expect($replaced->version)->toBe(2)->and($replaced->path)->not->toBe($before);
    Storage::disk('s3')->assertExists($replaced->path);
    expect(MediaPendingDeletion::query()->where('path', $before)->exists())->toBeTrue();
});

it('restores the original on the cloud disk', function () {
    $media = cloudUploadedPhoto($this->actor);
    $cropped = app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]);

    $restored = app(RestoreOriginal::class)->handle($this->actor, $cropped);

    expect($restored->version)->toBe(3)->and($restored->edits)->toBeNull()
        ->and(max($restored->width, $restored->height))->toBeGreaterThan(2000);
    Storage::disk('s3')->assertExists($restored->path);
    expect(Image::fromBytes((string) Storage::disk('s3')->get($restored->path))->width())->toBe($restored->width);
});

it('runs the optimize-existing batch on cloud files', function () {
    $bytes = makeImage('jpg', 3000, 2000);
    $media = Media::factory()->create(['disk' => 's3', 'mime' => 'image/jpeg', 'optimized_with' => null]);
    $media->forceFill(['path' => "media/2025/01/{$media->key}.jpg", 'size' => strlen($bytes), 'original_path' => null, 'thumb_path' => null])->save();
    Storage::disk('s3')->put($media->path, $bytes);

    app(OptimizeExistingMedia::class)->start($this->actor);
    app(OptimizeExistingMedia::class)->processBatch('queue', 30.0);
    $media->refresh();

    expect($media->mime)->toBe('image/webp')
        ->and($media->version)->toBe(2)
        ->and($media->original_path)->not->toBeNull()
        ->and($media->optimized_with)->not->toBeNull();
    Storage::disk('s3')->assertExists($media->path);
    Storage::disk('s3')->assertExists($media->original_path);
});

it('zips cloud files by streaming them from the disk', function () {
    $first = cloudUploadedPhoto($this->actor, 'a.jpg', 400, 300);
    $second = cloudUploadedPhoto($this->actor, 'b.jpg', 400, 300);

    $response = $this->actingAs($this->actor)
        ->get(route('admin.media.zip', ['ids' => [$first->id, $second->id]]))
        ->assertOk()->assertDownload('media.zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName($first->name))->toBe(Storage::disk('s3')->get($first->path))
        ->and(Storage::disk('s3')->streamed)->toBe([$first->path, $second->path]);
    $zip->close();
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('previews the kept original of a cloud file for the crop page', function () {
    $media = cloudUploadedPhoto($this->actor);

    $response = $this->actingAs($this->actor)->get(route('admin.media.source', $media))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('image/jpeg')
        ->and((int) $response->headers->get('X-Source-Width'))->toBe(3000);
});

it('deletes every cloud object at once', function () {
    $media = cloudUploadedPhoto($this->actor);
    $files = [$media->path, $media->thumb_path, $media->original_path];

    app(DeleteMedia::class)->handle($this->actor, $media);

    foreach ($files as $file) {
        Storage::disk('s3')->assertMissing($file);
    }
    expect(Media::query()->count())->toBe(0);
});

it('lists cloud files in the picker with direct thumbnails', function () {
    $media = cloudUploadedPhoto($this->actor);
    $this->actingAs($this->actor);

    Livewire::test('media-picker', ['accept' => 'image'])
        ->call('openPicker')
        ->assertSee($media->name)
        ->call('pick', $media->id)
        ->assertSeeHtml(Storage::disk('s3')->url($media->thumb_path));
});

it('decides isEditable on a cloud row with one existence check, memoized', function () {
    $media = cloudUploadedPhoto($this->actor);
    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->isEditable())->toBeTrue()
        ->and($fresh->isEditable())->toBeTrue();

    // A missing object makes it not editable.
    Storage::disk('s3')->delete($media->path);
    expect(Media::query()->findOrFail($media->id)->isEditable())->toBeFalse();
});

it('refuses an upload the cloud disk did not store and creates no row', function () {
    Storage::disk('s3')->failWrites = true;

    expect(fn () => cloudUploadedPhoto($this->actor))
        ->toThrow(MediaUploadException::class, __('media.errors.storage_failed'));

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('s3')->allFiles())->toBe([]);
});

it('keeps the row and schedules nothing when the cloud disk does not store a crop', function () {
    $media = cloudUploadedPhoto($this->actor);
    $before = [$media->path, $media->thumb_path, $media->original_path];
    Storage::disk('s3')->failWrites = true;

    expect(fn () => app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]))
        ->toThrow(MediaWriteException::class);

    $fresh = $media->fresh();
    expect($fresh->version)->toBe(1)
        ->and([$fresh->path, $fresh->thumb_path, $fresh->original_path])->toBe($before)
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
    foreach ($before as $file) {
        Storage::disk('s3')->assertExists($file);
    }
});

it('never deletes the objects of a rebuild that won the row while this one was writing', function () {
    $media = cloudUploadedPhoto($this->actor);
    $disk = Storage::disk('s3');
    $winner = null;

    // The per-row lock expired: another rebuild of version 1 committed version 2 on the same versioned paths.
    $disk->beforeWrite = function (string $path) use ($media, &$winner): void {
        if ($winner !== null) {
            return;
        }

        $winner = ['path' => $path, 'thumb_path' => MediaPaths::thumb('s3', $media->key, 2)];
        Media::query()->whereKey($media->id)->update(['version' => 2, ...$winner]);
    };

    expect(fn () => app(CropMedia::class)->handle($this->actor, $media, ['crop' => ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5]]))
        ->toThrow(MediaChangedException::class);

    $disk->assertExists($winner['path']);
    $disk->assertExists($winner['thumb_path']);
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});

it('deletes the objects the row points at when it is deleted, not the ones of a stale instance', function () {
    $stale = cloudUploadedPhoto($this->actor);
    $disk = Storage::disk('s3');

    // A crop or move committed version 2 after the instance was loaded.
    $current = ['path' => str_replace('-v1', '-v2', $stale->path), 'thumb_path' => MediaPaths::thumb('s3', $stale->key, 2), 'original_path' => null];
    $disk->put($current['path'], 'v2');
    $disk->put($current['thumb_path'], 'v2-thumb');
    Media::query()->whereKey($stale->id)->update(['version' => 2, ...$current]);

    expect(app(DeleteMedia::class)->handle($this->actor, $stale))->toBe(1);

    $disk->assertMissing($current['path']);
    $disk->assertMissing($current['thumb_path']);
});

it('stores an svg on the cloud disk as an inert download and still streams it sandboxed', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    $media = app(UploadMedia::class)->handle($this->actor, UploadedFile::fake()->createWithContent('logo.svg', $svg));
    $disk = Storage::disk('s3');

    expect($disk->options[$media->path]['ContentType'])->toBe('application/octet-stream')
        ->and($disk->options[$media->path]['ContentDisposition'])->toBe('attachment');

    $response = $this->actingAs($this->actor)->get($media->url())->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});
