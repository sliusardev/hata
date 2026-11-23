<?php

use Hata\Actions\Media\OptimizeImage;
use Hata\Actions\Media\RebuildMediaFile;
use Hata\Enums\MediaKind;
use Hata\Exceptions\MediaChangedException;
use Hata\Exceptions\MediaEditException;
use Hata\Exceptions\MediaUploadException;
use Hata\Models\Media;
use Hata\Models\MediaPendingDeletion;
use Hata\Support\Media\MediaSource;
use Hata\Support\Media\OptimizedImage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A media row whose files hold real bytes: the current file, a thumbnail, and optionally a kept original.
 *
 * @param  array<string, mixed>  $attributes
 */
function rebuildableMedia(string $contents, ?string $original = null, array $attributes = []): Media
{
    $media = Media::factory()->create($attributes);
    $media->forceFill([
        'original_path' => $original === null ? null : "media/originals/{$media->key}.jpg",
        'thumb_path' => "media/thumbs/{$media->key}.webp",
    ])->save();

    $disk = Storage::disk('local');
    $disk->put($media->path, $contents);
    $disk->put((string) $media->thumb_path, 'old-thumb');

    if ($original !== null) {
        $disk->put((string) $media->original_path, $original);
    }

    return $media;
}

it('rebuilds an image from its source and bumps the version', function () {
    $media = rebuildableMedia('old-file', makeImage('jpg', 3000, 2000), ['width' => 2560, 'height' => 1707]);
    $source = MediaSource::for($media);

    $rebuilt = app(RebuildMediaFile::class)->handle($media, $source->contents, $source->mime, null);

    expect($rebuilt->version)->toBe(2)
        ->and($rebuilt->mime)->toBe('image/webp')
        ->and($rebuilt->width)->toBe(2560)
        ->and($rebuilt->height)->toBe(1707)
        ->and($rebuilt->optimized_with)->toBe('webp:2560:80:exif0')
        ->and($rebuilt->edits)->toBeNull()
        ->and($rebuilt->fresh()->version)->toBe(2);

    $disk = Storage::disk('local');
    expect($disk->get($rebuilt->path))->not->toBe('old-file')
        ->and($rebuilt->size)->toBe(strlen((string) $disk->get($rebuilt->path)))
        ->and($disk->get((string) $rebuilt->thumb_path))->not->toBe('old-thumb')
        ->and($rebuilt->thumb_path)->toBe("media/thumbs/{$media->key}.webp");
    $disk->assertExists((string) $rebuilt->original_path);
});

it('writes the new file under this month and deletes the old path after commit', function () {
    $media = rebuildableMedia(makeImage('webp'));
    $oldPath = "media/2025/01/{$media->key}.webp";
    Storage::disk('local')->move($media->path, $oldPath);
    $media->forceFill(['path' => $oldPath])->save();

    $rebuilt = app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 400, 300), 'image/jpeg', null);

    expect($rebuilt->path)->toBe('media/'.now()->format('Y/m')."/{$media->key}.webp")
        ->and($rebuilt->key)->toBe($media->key)
        ->and($rebuilt->width)->toBe(400);
    Storage::disk('local')->assertExists($rebuilt->path);
    Storage::disk('local')->assertMissing($oldPath);
    expect(MediaPendingDeletion::query()->count())->toBe(0);
});

it('keeps the row and the old files when saving fails', function () {
    $media = rebuildableMedia('old-file', 'old-original');
    $oldPath = "media/2025/01/{$media->key}.webp";
    Storage::disk('local')->move($media->path, $oldPath);
    $media->forceFill(['path' => $oldPath])->save();
    $before = $media->fresh()->getAttributes();

    Media::updating(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 400, 300), 'image/jpeg', null, newOriginal: makeImage('png')))
        ->toThrow(RuntimeException::class, 'boom');

    $disk = Storage::disk('local');
    expect($media->fresh()->getAttributes())->toBe($before)
        ->and($media->path)->toBe($oldPath)
        ->and($media->version)->toBe(1)
        ->and($disk->get($oldPath))->toBe('old-file')
        ->and($disk->get((string) $media->original_path))->toBe('old-original')
        ->and($disk->get((string) $media->thumb_path))->toBe('old-thumb')
        ->and($disk->allFiles())->toEqualCanonicalizing([$oldPath, $media->original_path, $media->thumb_path]);
});

it('writes the old bytes back when saving fails on the same paths', function () {
    $media = rebuildableMedia('old-file', 'old-original');
    $before = $media->fresh()->getAttributes();

    Media::updating(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(RebuildMediaFile::class)->handle($media, makeImage('webp', 400, 300), 'image/webp', null, newOriginal: makeImage('jpg')))
        ->toThrow(RuntimeException::class, 'boom');

    $disk = Storage::disk('local');
    expect($media->fresh()->getAttributes())->toBe($before)
        ->and($disk->get($media->path))->toBe('old-file')
        ->and($disk->get((string) $media->original_path))->toBe('old-original')
        ->and($disk->get((string) $media->thumb_path))->toBe('old-thumb')
        ->and($disk->allFiles())->toEqualCanonicalizing([$media->path, $media->original_path, $media->thumb_path]);
});

it('renames the file when the extension changes', function () {
    $media = rebuildableMedia('old-file', null, ['name' => 'hero.jpg', 'mime' => 'image/jpeg']);
    $media->forceFill(['path' => 'media/'.now()->format('Y/m')."/{$media->key}.jpg"])->save();
    Storage::disk('local')->put($media->path, 'old-file');

    $rebuilt = app(RebuildMediaFile::class)->handle($media, makeImage('jpg'), 'image/jpeg', null);

    expect($rebuilt->name)->toBe('hero.webp')
        ->and($rebuilt->path)->toEndWith("{$media->key}.webp");
    Storage::disk('local')->assertMissing('media/'.now()->format('Y/m')."/{$media->key}.jpg");

    $other = rebuildableMedia('other', null, ['name' => 'hero.jpg', 'mime' => 'image/jpeg']);
    $other->forceFill(['path' => 'media/'.now()->format('Y/m')."/{$other->key}.jpg"])->save();

    expect(app(RebuildMediaFile::class)->handle($other, makeImage('jpg'), 'image/jpeg', null)->name)->toBe('hero-2.webp');
});

it('keeps the name when the extension stays and uses a given name', function () {
    $media = rebuildableMedia(makeImage('webp'), null, ['name' => 'hero.webp']);

    expect(app(RebuildMediaFile::class)->handle($media, makeImage('webp'), 'image/webp', null)->name)->toBe('hero.webp')
        ->and(app(RebuildMediaFile::class)->handle($media, makeImage('webp'), 'image/webp', null, newName: 'new.webp')->name)->toBe('new.webp');
});

it('stores the edits it applied, or bakes them in', function () {
    $media = rebuildableMedia(makeImage('jpg', 800, 600));
    $edits = ['crop' => ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 300]];

    $kept = app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 800, 600), 'image/jpeg', $edits);

    expect($kept->width)->toBe(400)
        ->and($kept->height)->toBe(300)
        ->and($kept->edits)->toEqual(['rotate' => 0, 'flip_h' => false, 'flip_v' => false, 'crop' => ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 300], 'size' => null]);

    $baked = app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 800, 600), 'image/jpeg', $edits, keepEdits: false);

    expect($baked->width)->toBe(400)
        ->and($baked->edits)->toBeNull()
        ->and($baked->fresh()->edits)->toBeNull();
});

it('writes nothing when edits can not be applied', function (string $contents, string $mime, string $failure) {
    $media = rebuildableMedia('old-file', 'old-original');
    $before = $media->fresh()->getAttributes();

    try {
        app(RebuildMediaFile::class)->handle($media, $contents, $mime, ['rotate' => 90]);
        $this->fail('Expected MediaEditException.');
    } catch (MediaEditException $e) {
        expect($e->failure)->toBe($failure);
    }

    $disk = Storage::disk('local');
    expect($media->fresh()->getAttributes())->toBe($before)
        ->and($disk->get($media->path))->toBe('old-file')
        ->and($disk->allFiles())->toHaveCount(3);
})->with([
    'animated gif' => fn () => [animatedGif(), 'image/gif', 'not_editable'],
    'unreadable' => fn () => ['not an image', 'image/jpeg', 'unreadable'],
    'pdf' => fn () => ['%PDF-1.4', 'application/pdf', 'not_editable'],
]);

it('writes a new original and deletes the replaced one', function () {
    $media = rebuildableMedia(makeImage('webp'), 'old-original');
    $oldOriginal = (string) $media->original_path;

    $rebuilt = app(RebuildMediaFile::class)->handle($media, makeImage('png'), 'image/png', null, newOriginal: makeImage('png'));

    expect($rebuilt->original_path)->toBe("media/originals/{$media->key}.png");
    Storage::disk('local')->assertExists((string) $rebuilt->original_path);
    Storage::disk('local')->assertMissing($oldOriginal);
});

it('drops the original and saves extra attributes', function () {
    $media = rebuildableMedia(makeImage('webp'), 'old-original');
    $oldOriginal = (string) $media->original_path;

    $rebuilt = app(RebuildMediaFile::class)->handle(
        $media, makeImage('webp'), 'image/webp', null,
        dropOriginal: true, attributes: ['original_size' => 1234, 'uploaded_extension' => 'png'],
    );

    expect($rebuilt->original_path)->toBeNull()
        ->and($rebuilt->fresh()->original_size)->toBe(1234)
        ->and($rebuilt->fresh()->uploaded_extension)->toBe('png');
    Storage::disk('local')->assertMissing($oldOriginal);
});

it('stores a document as given and drops its thumbnail', function () {
    $media = rebuildableMedia('%PDF-old', null, ['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'name' => 'doc.pdf']);
    $media->forceFill(['path' => 'media/'.now()->format('Y/m')."/{$media->key}.pdf"])->save();
    Storage::disk('local')->put($media->path, '%PDF-old');
    $oldThumb = (string) $media->thumb_path;

    $rebuilt = app(RebuildMediaFile::class)->handle($media, '%PDF-1.4 new', 'application/pdf', null);

    expect($rebuilt->name)->toBe('doc.pdf')
        ->and($rebuilt->size)->toBe(12)
        ->and($rebuilt->thumb_path)->toBeNull()
        ->and($rebuilt->width)->toBeNull()
        ->and(Storage::disk('local')->get($rebuilt->path))->toBe('%PDF-1.4 new');
    Storage::disk('local')->assertMissing($oldThumb);
});

it('refuses heic the optimizer could not convert', function () {
    $media = rebuildableMedia('old-file');
    $this->mock(OptimizeImage::class)->shouldReceive('handle')
        ->andReturn(new OptimizedImage('heic', 'image/heic', 'heic', 640, 480, optimized: false, failure: 'unreadable'));

    expect(fn () => app(RebuildMediaFile::class)->handle($media, 'heic', 'image/heic', null))
        ->toThrow(MediaUploadException::class, __('media.errors.heic_unsupported'));

    expect(Storage::disk('local')->get($media->path))->toBe('old-file')
        ->and($media->fresh()->version)->toBe(1);
});

it('reads the original when one is kept', function () {
    $media = rebuildableMedia('current', makeImage('jpg', 300, 200));

    $source = MediaSource::for($media);

    expect($source->isOriginal)->toBeTrue()
        ->and($source->mime)->toBe('image/jpeg')
        ->and($source->contents)->toBe(Storage::disk('local')->get((string) $media->original_path))
        ->and($source->orientedSize())->toBe([300, 200]);
});

it('falls back to the current file', function () {
    $media = rebuildableMedia(makeImage('webp', 300, 200), 'gone');
    Storage::disk('local')->delete((string) $media->original_path);

    $source = MediaSource::for($media);

    expect($source->isOriginal)->toBeFalse()
        ->and($source->mime)->toBe('image/webp')
        ->and($source->contents)->toBe(Storage::disk('local')->get($media->path))
        ->and(MediaSource::for(rebuildableMedia(makeImage('webp')))->isOriginal)->toBeFalse();
});

it('forgets whether the file is editable after a rebuild', function () {
    $media = rebuildableMedia(animatedGif(), null, ['mime' => 'image/gif', 'name' => 'a.gif', 'animated' => true]);
    expect($media->isEditable())->toBeFalse();

    app(RebuildMediaFile::class)->handle($media, makeImage('jpg'), 'image/jpeg', null);

    expect($media->isEditable())->toBeTrue();
});

it('stores the new original with the extension of the source when it is the source itself', function () {
    $media = rebuildableMedia('old-file');
    $this->mock(OptimizeImage::class, function ($mock): void {
        $mock->shouldReceive('handle')->andReturn(new OptimizedImage('webp-bytes', 'image/webp', 'webp', 640, 480, optimized: true, fingerprint: 'fp'));
        $mock->shouldReceive('thumbnail')->andReturn(null);
    });

    $rebuilt = app(RebuildMediaFile::class)->handle($media, 'heic-bytes', 'image/heic', null, newOriginal: 'heic-bytes');

    expect($rebuilt->original_path)->toBe("media/originals/{$media->key}.heic");
    Storage::disk('local')->assertExists("media/originals/{$media->key}.heic");
});

it('refuses to rebuild from an instance loaded before another rebuild, instead of overwriting it', function () {
    $media = rebuildableMedia(makeImage('webp'));
    $stale = Media::query()->findOrFail($media->id);

    app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 400, 300), 'image/jpeg', null);

    expect(fn () => app(RebuildMediaFile::class)->handle($stale, makeImage('jpg', 300, 200), 'image/jpeg', null))
        ->toThrow(MediaChangedException::class);

    expect($stale->version)->toBe(1)
        ->and($media->fresh()->version)->toBe(2)
        ->and($media->fresh()->width)->toBe(400);
});

it('stores that a rebuild from an animated gif is animated', function () {
    $media = rebuildableMedia('old-file', null, ['mime' => 'image/gif', 'name' => 'a.gif']);

    $rebuilt = app(RebuildMediaFile::class)->handle($media, animatedGif(), 'image/gif', null);

    expect($rebuilt->fresh()->animated)->toBeTrue();
});

it('stores a ready optimized result', function () {
    $media = rebuildableMedia('old-file', null, ['width' => 10, 'height' => 10]);
    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg', null);

    $stored = app(RebuildMediaFile::class)->store($media, $result, null);

    $disk = Storage::disk('local');
    expect($stored->version)->toBe(2)
        ->and($stored->mime)->toBe('image/webp')
        ->and($stored->optimized_with)->toBe($result->fingerprint)
        ->and($stored->size)->toBe(strlen($result->contents))
        ->and($stored->animated)->toBe($result->animated)
        ->and($disk->get($stored->path))->toBe($result->contents)
        ->and($disk->get((string) $stored->thumb_path))->toBe($result->thumbnail)
        ->and($stored->fresh()->version)->toBe(2);
});

it('marks a file optimized without rewriting it', function () {
    $media = rebuildableMedia('old-file', null, ['optimized_with' => null, 'optimize_failure' => 'unreadable']);

    app(RebuildMediaFile::class)->markOptimized($media, 'webp:2560:80:exif0');

    $fresh = $media->fresh();
    expect($fresh->optimized_with)->toBe('webp:2560:80:exif0')
        ->and($fresh->optimize_failure)->toBeNull()
        ->and($fresh->version)->toBe(1)
        ->and(Storage::disk('local')->get($fresh->path))->toBe('old-file');
});

it('refuses to store over a version changed meanwhile and leaves the files and the row as they were', function () {
    $media = rebuildableMedia('old-file', null, ['width' => 10, 'height' => 10]);
    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 400, 300), 'image/jpeg', null);
    // A crop or replace stored a new version after $media was loaded.
    Media::query()->whereKey($media->id)->update(['version' => 2]);
    $row = Media::query()->findOrFail($media->id)->getAttributes();

    expect(fn () => app(RebuildMediaFile::class)->store($media, $result, null, expectedVersion: 1))
        ->toThrow(MediaChangedException::class);

    $disk = Storage::disk('local');
    expect(Media::query()->findOrFail($media->id)->getAttributes())->toBe($row)
        ->and($media->version)->toBe(1)
        ->and($disk->get($media->path))->toBe('old-file')
        ->and($disk->get((string) $media->thumb_path))->toBe('old-thumb')
        ->and($disk->allFiles())->toEqualCanonicalizing([$media->path, $media->thumb_path]);
});

it('leaves none of its files behind when the row was deleted meanwhile', function () {
    $media = rebuildableMedia('old-file');
    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 400, 300), 'image/jpeg', null);
    // Deleted meanwhile; its files go after that commit, so they may still be there now.
    Media::query()->whereKey($media->id)->delete();

    expect(fn () => app(RebuildMediaFile::class)->store($media, $result, null, newOriginal: 'original-bytes', expectedVersion: 1))
        ->toThrow(MediaChangedException::class);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('marks a file optimized only while its version is the expected one', function () {
    $media = rebuildableMedia('old-file', null, ['optimized_with' => null]);
    Media::query()->whereKey($media->id)->update(['version' => 2, 'edits' => ['rotate' => 90, 'flip_h' => false, 'flip_v' => false, 'crop' => null, 'size' => null]]);
    $media->edits = null;

    expect(fn () => app(RebuildMediaFile::class)->markOptimized($media, 'webp:2560:80:exif0', expectedVersion: 1))
        ->toThrow(MediaChangedException::class);

    $fresh = $media->fresh();
    expect($fresh->optimized_with)->toBeNull()
        ->and($fresh->edits)->not->toBeNull();

    app(RebuildMediaFile::class)->markOptimized($fresh, 'webp:2560:80:exif0', expectedVersion: 2);

    expect($media->fresh()->optimized_with)->toBe('webp:2560:80:exif0');
});

/**
 * A row on the faked cloud disk with its version-1 file, thumbnail and kept original.
 */
function cloudMedia(): Media
{
    $media = Media::factory()->create(['disk' => 's3', 'width' => 2560, 'height' => 1707]);
    $media->forceFill([
        'path' => "media/2026/10/{$media->key}-v1.webp",
        'thumb_path' => "media/thumbs/{$media->key}-v1.webp",
        'original_path' => "media/originals/{$media->key}-v1.jpg",
    ])->save();

    $disk = Storage::disk('s3');
    $disk->put($media->path, 'old-file');
    $disk->put((string) $media->thumb_path, 'old-thumb');
    $disk->put((string) $media->original_path, makeImage('jpg', 3000, 2000));

    return $media;
}

it('writes a cloud rebuild beside the old version and keeps the replaced objects for 7 days', function () {
    $this->freezeSecond();
    $disk = fakeCloudDisk();
    $media = cloudMedia();
    [$oldPath, $oldThumb] = [$media->path, (string) $media->thumb_path];

    $original = makeImage('jpg', 1200, 800);

    $rebuilt = app(RebuildMediaFile::class)->handle($media, $original, 'image/jpeg', null, newOriginal: $original);

    expect($rebuilt->version)->toBe(2)
        ->and($rebuilt->path)->toBe('media/'.now()->format('Y/m')."/{$media->key}-v2.webp")
        ->and($rebuilt->thumb_path)->toBe("media/thumbs/{$media->key}-v2.webp")
        ->and($rebuilt->original_path)->toBe("media/originals/{$media->key}-v2.jpg")
        ->and($disk->get($oldPath))->toBe('old-file')
        ->and($disk->get($oldThumb))->toBe('old-thumb')
        ->and($disk->options[$rebuilt->path])->toBe(['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=604800'])
        ->and($disk->options[(string) $rebuilt->thumb_path])->toBe(['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age=604800'])
        ->and($disk->options[(string) $rebuilt->original_path])->toBe(['ContentType' => 'image/jpeg', 'CacheControl' => 'public, max-age=604800']);
    $disk->assertExists([$rebuilt->path, (string) $rebuilt->thumb_path, (string) $rebuilt->original_path, "media/originals/{$media->key}-v1.jpg"]);

    $pending = MediaPendingDeletion::query()->orderBy('path')->get();
    expect($pending->map(fn (MediaPendingDeletion $row) => [$row->disk, $row->path])->all())->toBe([
        ['s3', "media/2026/10/{$media->key}-v1.webp"],
        ['s3', "media/originals/{$media->key}-v1.jpg"],
        ['s3', "media/thumbs/{$media->key}-v1.webp"],
    ])
        ->and($pending->every(fn (MediaPendingDeletion $row) => $row->delete_after->equalTo(now()->addDays(7))))->toBeTrue();
});

it('records no pending deletion and removes its new objects when a cloud rebuild is refused', function () {
    $disk = fakeCloudDisk();
    $media = cloudMedia();
    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 400, 300), 'image/jpeg', null);
    Media::query()->whereKey($media->id)->update(['version' => 2]);

    expect(fn () => app(RebuildMediaFile::class)->store($media, $result, null, expectedVersion: 1))
        ->toThrow(MediaChangedException::class);

    expect(MediaPendingDeletion::query()->count())->toBe(0)
        ->and($disk->allFiles())->toEqualCanonicalizing([$media->path, $media->thumb_path, $media->original_path]);
});

it('refuses a cloud rebuild from a stale row and leaves the committed newer version alone', function () {
    $disk = fakeCloudDisk();
    $media = cloudMedia();
    // Another rebuild committed version 2 after $media (version 1) was loaded.
    $v2 = ['path' => "media/2026/11/{$media->key}-v2.webp", 'thumb_path' => "media/thumbs/{$media->key}-v2.webp"];
    Media::query()->whereKey($media->id)->update([...$v2, 'version' => 2]);
    $disk->put($v2['path'], 'committed-v2');
    $disk->put($v2['thumb_path'], 'committed-v2-thumb');
    $row = Media::query()->findOrFail($media->id)->getAttributes();
    $original = makeImage('jpg', 1200, 800);

    expect(fn () => app(RebuildMediaFile::class)->handle($media, $original, 'image/jpeg', null, newOriginal: $original))
        ->toThrow(MediaChangedException::class);

    expect(Media::query()->findOrFail($media->id)->getAttributes())->toBe($row)
        ->and($disk->get($v2['path']))->toBe('committed-v2')
        ->and($disk->get($v2['thumb_path']))->toBe('committed-v2-thumb')
        ->and($disk->allFiles())->toEqualCanonicalizing([$media->path, $media->thumb_path, $media->original_path, ...array_values($v2)])
        ->and(MediaPendingDeletion::query()->count())->toBe(0);
});

it('refuses a local rebuild from a stale row without touching the files', function () {
    $media = rebuildableMedia('old-file');
    Media::query()->whereKey($media->id)->update(['version' => 2]);

    expect(fn () => app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 400, 300), 'image/jpeg', null))
        ->toThrow(MediaChangedException::class);

    expect(Media::query()->findOrFail($media->id)->version)->toBe(2)
        ->and(Storage::disk('local')->get($media->path))->toBe('old-file');
});

it('refuses a rebuild while another rebuild of the same file holds its lock', function () {
    $media = rebuildableMedia('old-file');
    $lock = Cache::lock(RebuildMediaFile::lockName($media), 60);
    $lock->get();

    try {
        expect(fn () => app(RebuildMediaFile::class)->handle($media, makeImage('jpg', 400, 300), 'image/jpeg', null))
            ->toThrow(MediaChangedException::class);
    } finally {
        $lock->release();
    }

    expect(Media::query()->findOrFail($media->id)->version)->toBe(1)
        ->and(Storage::disk('local')->get($media->path))->toBe('old-file');
});
