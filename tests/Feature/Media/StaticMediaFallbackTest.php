<?php

use App\Enums\MediaKind;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    usePublicMediaDisk();
});

function publicMedia(array $attributes = [], string $content = 'static-body'): Media
{
    $media = Media::factory()->create([
        'disk' => 'public',
        'thumb_path' => 'media/thumbs/'.fake()->unique()->lexify('????????').'-v1.webp',
        ...$attributes,
    ]);
    Storage::disk('public')->put($media->path, $content);
    Storage::disk('public')->put((string) $media->thumb_path, 'thumb-body');

    return $media;
}

function staticUrl(string $path): string
{
    return '/storage/'.$path;
}

it('serves a raster file referenced by a public row', function () {
    $media = publicMedia();

    $response = $this->get(staticUrl($media->path))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public', 'max-age=604800')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/webp')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->streamedContent())->toBe('static-body');
});

it('serves a thumbnail referenced by a public row', function () {
    $media = publicMedia();

    $response = $this->get(staticUrl((string) $media->thumb_path))->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=604800')
        ->and($response->streamedContent())->toBe('thumb-body');
});

it('answers a HEAD request without a body', function () {
    $media = publicMedia();

    $response = $this->call('HEAD', staticUrl($media->path))->assertOk();

    expect($response->headers->get('Content-Length'))->toBe((string) strlen('static-body'));
});

it('returns 404 for an unknown path', function () {
    $this->get('/storage/media/2026/11/nothing.webp')->assertNotFound();
});

it('returns 404 for a path with dot segments', function () {
    $media = publicMedia();

    $this->get('/storage/media/../'.$media->path)->assertNotFound();
    $this->get('/storage/media/2026/../'.basename($media->path))->assertNotFound();
});

it('returns 404 for a file on the public disk that no row references', function () {
    Storage::disk('public')->put('media/2026/11/stray.webp', 'stray');

    $this->get('/storage/media/2026/11/stray.webp')->assertNotFound();
});

it('returns 404 for a row on another disk', function () {
    $media = Media::factory()->create(['disk' => 'local']);
    Storage::disk('local')->put($media->path, 'private');
    Storage::disk('public')->put($media->path, 'planted');

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 for an svg', function () {
    $media = publicMedia(['mime' => 'image/svg+xml', 'name' => 'logo.svg', 'path' => 'media/2026/11/logo.svg', 'thumb_path' => null]);

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 for a document', function () {
    $media = publicMedia(['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'path' => 'media/2026/11/doc.pdf', 'thumb_path' => null]);

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 when the row points at a file that is gone', function () {
    $media = publicMedia();
    Storage::disk('public')->delete($media->path);

    $this->get(staticUrl($media->path))->assertNotFound();
});
