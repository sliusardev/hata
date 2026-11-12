<?php

use App\Enums\MediaKind;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    usePublicMediaDisk();
});

/**
 * A row on the public disk with versioned paths named by its key, as an upload writes them, and both files stored.
 * `{key}` in a given path is replaced by the row's key.
 */
function publicMedia(array $attributes = [], string $content = 'static-body'): Media
{
    $key = (string) Str::ulid();
    $attributes = array_map(fn ($value) => is_string($value) ? str_replace('{key}', $key, $value) : $value, $attributes);

    $media = Media::factory()->create([
        'key' => $key,
        'disk' => 'public',
        'path' => "media/2026/11/{$key}-v1.webp",
        'thumb_path' => "media/thumbs/{$key}-v1.webp",
        ...$attributes,
    ]);
    Storage::disk('public')->put($media->path, $content);

    if ($media->thumb_path !== null) {
        Storage::disk('public')->put($media->thumb_path, 'thumb-body');
    }

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
    $key = (string) Str::ulid();
    $media = Media::factory()->create(['key' => $key, 'disk' => 'local', 'path' => "media/2026/11/{$key}-v1.webp"]);
    Storage::disk('local')->put($media->path, 'private');
    Storage::disk('public')->put($media->path, 'planted');

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 for an svg', function () {
    $media = publicMedia(['mime' => 'image/svg+xml', 'name' => 'logo.svg', 'path' => 'media/2026/11/{key}-v1.svg', 'thumb_path' => null]);

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 for a document', function () {
    $media = publicMedia(['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'path' => 'media/2026/11/{key}-v1.pdf', 'thumb_path' => null]);

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('returns 404 when the row points at a file that is gone', function () {
    $media = publicMedia();
    Storage::disk('public')->delete($media->path);

    $this->get(staticUrl($media->path))->assertNotFound();
});

it('finds the row with one query by the key in the file name', function () {
    $media = publicMedia();

    DB::enableQueryLog();
    $this->get(staticUrl($media->path))->assertOk();
    $this->get(staticUrl((string) $media->thumb_path))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2)
        ->and($queries[0]['query'])->toContain('key')
        ->and($queries[0]['query'])->not->toContain('path');
});

it('redirects an older version of the file or thumbnail to the current one, uncached', function () {
    $media = publicMedia([
        'version' => 3,
        'path' => 'media/2026/11/{key}-v3.webp',
        'thumb_path' => 'media/thumbs/{key}-v3.webp',
    ]);

    $file = $this->get("/storage/media/2026/10/{$media->key}-v2.jpg")
        ->assertRedirect(route('media.static', ['path' => "2026/11/{$media->key}-v3.webp"]));
    $thumb = $this->get("/storage/media/thumbs/{$media->key}-v1.webp")
        ->assertRedirect(route('media.static', ['path' => "thumbs/{$media->key}-v3.webp"]));

    expect($file->headers->get('Location'))->toEndWith("/storage/media/2026/11/{$media->key}-v3.webp")
        ->and($file->getStatusCode())->toBe(302)
        ->and($file->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($thumb->headers->get('Cache-Control'))->toContain('no-cache');
});

it('returns 404 for a newer version, a lowercase key or trailing junk', function () {
    $media = publicMedia();
    $lower = strtolower($media->key);

    $this->get("/storage/media/2026/11/{$media->key}-v2.webp")->assertNotFound();
    $this->get("/storage/media/2026/11/{$lower}-v1.webp")->assertNotFound();
    $this->get(staticUrl($media->path).'x')->assertNotFound();
    $this->get(staticUrl($media->path).'.bak')->assertNotFound();
    $this->get(staticUrl($media->path).'/x')->assertNotFound();
    $this->get("/storage/media/thumbs/{$media->key}-v1.jpg")->assertNotFound();
});

it('does not redirect an older thumbnail of a row that has none now', function () {
    $media = publicMedia(['version' => 2, 'path' => 'media/2026/11/{key}-v2.webp', 'thumb_path' => null]);

    $this->get("/storage/media/thumbs/{$media->key}-v1.webp")->assertNotFound();
});
