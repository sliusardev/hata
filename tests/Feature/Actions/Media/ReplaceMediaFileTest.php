<?php

use App\Models\User;
use Hata\Actions\Media\ReplaceMediaFile;
use Hata\Enums\MediaKind;
use Hata\Exceptions\MediaUploadException;
use Hata\Models\Media;
use Hata\Support\Media\MediaDisks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

function replaceableMedia(string $ext = 'webp', array $attributes = []): Media
{
    $media = Media::factory()->create([
        'alt' => ['en' => 'A cat'],
        'title' => 'Cat',
        'name' => "cat.{$ext}",
        'path' => 'media/2020/01/old-'.uniqid().".{$ext}",
        'size' => 10,
        ...$attributes,
    ]);
    $media->forceFill(['thumb_path' => "media/thumbs/{$media->key}.webp", 'original_path' => "media/originals/{$media->key}.jpg"])->save();
    Storage::disk('local')->put($media->path, 'old');
    Storage::disk('local')->put($media->thumb_path, 'old-thumb');
    Storage::disk('local')->put($media->original_path, 'old-original');

    return $media;
}

it('replaces an image and keeps id, key and alt', function () {
    $media = replaceableMedia();
    $oldPath = $media->path;
    $oldOriginal = $media->original_path;
    $id = $media->id;
    $key = $media->key;
    $file = uploadedImage('New photo.webp', makeImage('webp', 1000, 500));

    $result = app(ReplaceMediaFile::class)->handle($this->actor, $media, $file, 9_000_000, 'heic');

    expect($result->id)->toBe($id)
        ->and($result->key)->toBe($key)
        ->and($result->version)->toBe(2)
        ->and($result->alt)->toBe(['en' => 'A cat'])
        ->and($result->title)->toBe('Cat')
        ->and($result->edits)->toBeNull()
        ->and($result->name)->toBe('new-photo.webp')
        ->and($result->original_size)->toBe(9_000_000)
        ->and($result->uploaded_extension)->toBe('heic')
        ->and($result->width)->toBe(1000);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists([$result->path, $result->thumb_path, $result->original_path]);
    expect(Storage::disk('local')->get($result->thumb_path))->not->toBe('old-thumb')
        ->and($result->original_path)->not->toBe($oldOriginal);
    Storage::disk('local')->assertMissing($oldOriginal);
});

it('drops the old original when originals are not kept', function () {
    config(['media.optimize.keep_originals' => false]);
    $media = replaceableMedia();
    $old = $media->original_path;

    $result = app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('a.webp', makeImage('webp')));

    expect($result->original_path)->toBeNull();
    Storage::disk('local')->assertMissing($old);
});

it('renames and redirects the old name when the extension changes', function () {
    $media = replaceableMedia('jpg', ['mime' => 'image/jpeg']);
    $media->forceFill(['path' => 'media/2020/01/'.$media->key.'.jpg'])->save();
    Storage::disk('local')->put($media->path, 'old');
    $oldName = $media->name;
    $key = $media->key;

    $result = app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('cat.png', makeImage('png')));

    expect($result->name)->toBe('cat.webp');
    Storage::disk('local')->assertMissing("media/2020/01/{$key}.jpg");
    $this->get(route('media.show', [$key, $oldName]))
        ->assertRedirect($result->url())
        ->assertStatus(301);
    $this->get($result->url())->assertOk();
});

it('refuses a file of another kind and changes nothing', function () {
    $media = replaceableMedia();
    $before = $media->fresh()->getAttributes();
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    try {
        app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('a.pdf', $pdf));
        $this->fail('Expected an exception.');
    } catch (MediaUploadException $e) {
        expect($e->getMessage())->toBe(__('hata::media.errors.other_kind', ['kind' => __('hata::media.errors.kinds.image')]));
    }

    expect($media->fresh()->getAttributes())->toBe($before);
    expect(Storage::disk('local')->get($media->path))->toBe('old');
});

it('replaces a document without processing', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    $media = Media::factory()->create(['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'name' => 'report.pdf', 'width' => null, 'height' => null, 'path' => 'media/2020/01/doc.pdf']);
    Storage::disk('local')->put($media->path, 'old');

    $result = app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('Report 2.pdf', $pdf));

    expect($result->name)->toBe('report-2.pdf')
        ->and($result->thumb_path)->toBeNull()
        ->and($result->original_path)->toBeNull()
        ->and($result->optimized_with)->toBeNull()
        ->and($result->version)->toBe(2)
        ->and(Storage::disk('local')->get($result->path))->toBe($pdf);
});

it('applies the size limit with the picked size', function () {
    $media = replaceableMedia();
    $limit = 30 * 1024 * 1024;
    config(['media.limits.image_mb' => 30]);

    expect(fn () => app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage('a.webp', makeImage('webp')), $limit + 1))
        ->toThrow(MediaUploadException::class);

    expect($media->fresh()->version)->toBe(1);
});

it('requires media.edit', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create();

    expect(fn () => app(ReplaceMediaFile::class)->handle($actor, replaceableMedia(), uploadedImage('a.webp', makeImage('webp'))))
        ->toThrow(AuthorizationException::class);
});

it('refuses to replace across the raster and svg line on any site disk and writes nothing', function (string $siteDisk, string $from, string $to) {
    // Every disk is listed below, so the cloud one is faked even when the site does not use it.
    Storage::fake('s3');
    match ($siteDisk) {
        'public' => usePublicMediaDisk(),
        's3' => fakeCloudDisk(),
        default => null,
    };
    config(['media.disk' => $siteDisk]);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    $files = ['svg' => ['logo.svg', $svg, 'image/svg+xml'], 'png' => ['logo.png', makeImage('png'), 'image/png']];
    [, $bytes, $mime] = $files[$from];
    $media = Media::factory()->create(['disk' => MediaDisks::diskFor($siteDisk, MediaKind::Image, $mime), 'mime' => $mime, 'name' => $files[$from][0], 'path' => "media/2020/01/old.{$from}"]);
    Storage::disk($media->disk)->put($media->path, $bytes);
    $before = $media->fresh()->getAttributes();
    $stored = collect(['local', 'public', 's3'])->mapWithKeys(fn (string $disk) => [$disk => Storage::disk($disk)->allFiles()])->all();

    try {
        app(ReplaceMediaFile::class)->handle($this->actor, $media, uploadedImage($files[$to][0], $files[$to][1]));
        $this->fail('Expected an exception.');
    } catch (MediaUploadException $e) {
        expect($e->getMessage())->toBe(__('hata::media.replace.svg_boundary'));
    }

    expect($media->fresh()->getAttributes())->toBe($before)
        ->and(collect(['local', 'public', 's3'])->mapWithKeys(fn (string $disk) => [$disk => Storage::disk($disk)->allFiles()])->all())->toBe($stored);
})->with(['local', 'public', 's3'])->with([
    'raster to svg' => ['png', 'svg'],
    'svg to raster' => ['svg', 'png'],
]);
