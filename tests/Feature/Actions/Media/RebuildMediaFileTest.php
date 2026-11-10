<?php

use App\Actions\Media\OptimizeImage;
use App\Actions\Media\RebuildMediaFile;
use App\Enums\MediaKind;
use App\Exceptions\MediaEditException;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Support\Media\MediaSource;
use App\Support\Media\OptimizedImage;
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
    $media = rebuildableMedia(animatedGif(), null, ['mime' => 'image/gif', 'name' => 'a.gif']);
    expect($media->isEditable())->toBeFalse();

    app(RebuildMediaFile::class)->handle($media, makeImage('jpg'), 'image/jpeg', null);

    expect($media->isEditable())->toBeTrue();
});
