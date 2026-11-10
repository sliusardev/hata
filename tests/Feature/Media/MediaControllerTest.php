<?php

use App\Enums\MediaKind;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

function storedMedia(array $attributes = [], string $content = 'file-body'): Media
{
    $media = Media::factory()->create($attributes);
    Storage::disk('local')->put($media->path, $content);

    return $media;
}

it('serves an image inline with cache headers', function () {
    $media = storedMedia();

    $response = $this->get($media->url())->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public', 'max-age=604800')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('Content-Type'))->toStartWith('image/webp')
        ->and($response->headers->get('ETag'))->toStartWith('"')
        ->and($response->headers->has('Last-Modified'))->toBeTrue();
    expect($response->streamedContent())->toBe('file-body');
});

it('returns 304 when the etag matches', function () {
    $media = storedMedia();
    $etag = $this->get($media->url())->headers->get('ETag');

    $response = $this->get($media->url(), ['If-None-Match' => $etag])->assertStatus(304);

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toBe('');
});

it('redirects an old name to the current one', function () {
    $media = storedMedia(['name' => 'hero.webp']);

    $this->get('/media/'.$media->key.'/hero.jpg')
        ->assertStatus(301)
        ->assertRedirect('/media/'.$media->key.'/hero.webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $media->thumb_path = 'media/thumbs/x.webp';
    $media->save();

    $this->get('/media/'.$media->key.'/hero.jpg?thumb=1')
        ->assertRedirect('/media/'.$media->key.'/hero.webp?thumb=1');
});

it('sends svg with a sandbox csp', function () {
    $media = storedMedia(['name' => 'logo.svg', 'mime' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg"/>');

    $this->get($media->url())
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sends csv as a plain text attachment', function () {
    $media = storedMedia([
        'name' => 'data.csv', 'mime' => 'text/csv', 'kind' => MediaKind::Document,
    ], "a,b\n1,2\n");

    $response = $this->get($media->url())->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('serves the thumbnail', function () {
    $media = storedMedia(['thumb_path' => 'media/thumbs/t.webp']);
    Storage::disk('local')->put('media/thumbs/t.webp', 'thumb-body');

    $response = $this->get($media->thumbUrl())->assertOk();

    expect($response->streamedContent())->toBe('thumb-body');

    $media->update(['thumb_path' => null]);

    $this->get($media->url().'?thumb=1')->assertNotFound();
});

it('returns 404 for an unknown key and when the file is missing', function () {
    $this->get('/media/01arz3ndektsv4rrffq69g5fav/a.webp')->assertNotFound();

    $media = Media::factory()->create();

    $this->get($media->url())->assertNotFound();
});

it('needs no login', function () {
    $media = storedMedia();

    $this->assertGuest();
    $this->get($media->url())->assertOk();
});

it('redirects files on a cloud disk to the disk url', function () {
    Storage::fake('cdn', ['url' => 'https://cdn.example.test']);
    $media = Media::factory()->create(['disk' => 'cdn']);

    $this->get($media->url())
        ->assertRedirect(Storage::disk('cdn')->url($media->path))
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
