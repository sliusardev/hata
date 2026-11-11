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
        ->assertRedirect('/media/'.$media->key.'/hero.webp?v=1')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

    $media->thumb_path = 'media/thumbs/x.webp';
    $media->save();

    $this->get('/media/'.$media->key.'/hero.jpg?thumb=1')
        ->assertRedirect('/media/'.$media->key.'/hero.webp?thumb=1&v=1');
});

it('keeps v on the old-name redirect', function () {
    $media = storedMedia(['name' => 'hero.webp', 'version' => 4]);

    $this->get('/media/'.$media->key.'/hero.jpg?v=4')
        ->assertStatus(301)
        ->assertRedirect('/media/'.$media->key.'/hero.webp?v=4');

    $this->get('/media/'.$media->key.'/hero.jpg?v=2')
        ->assertRedirect('/media/'.$media->key.'/hero.webp?v=2');
});

it('caches the current version for a week', function () {
    $media = storedMedia(['version' => 3]);

    $response = $this->get($media->url())->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('public', 'max-age=604800');
});

it('does not cache a stale or missing version', function () {
    $media = storedMedia(['version' => 2]);
    $stale = route('media.show', [$media->key, $media->name, 'v' => 1]);
    $missing = route('media.show', [$media->key, $media->name]);

    foreach ([$stale, $missing] as $url) {
        $response = $this->get($url)->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-cache')
            ->not->toContain('max-age=604800')
            ->and($response->headers->get('ETag'))->toStartWith('"');
    }
});

it('changes the etag when the version changes', function () {
    $media = storedMedia(['version' => 1]);
    $before = $this->get($media->url())->headers->get('ETag');

    $media->forceFill(['version' => 2])->saveQuietly();

    expect($this->get($media->fresh()->url())->headers->get('ETag'))->not->toBe($before);
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

    $this->get($media->url().'&thumb=1')->assertNotFound();
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

it('serves a local-driver disk that is not named local', function () {
    Storage::fake('media');
    config(['filesystems.disks.media.driver' => 'local']);
    $media = Media::factory()->create(['disk' => 'media']);
    Storage::disk('media')->put($media->path, 'body');

    $this->get($media->url())->assertOk();
});

it('returns 404 for a thumbnail request without a thumbnail before any rename redirect', function () {
    $media = storedMedia(['name' => 'hero.webp']);

    $this->get('/media/'.$media->key.'/old.jpg?thumb=1')->assertNotFound();
});

it('accepts a lowercase key', function () {
    $media = storedMedia();

    $this->get('/media/'.strtolower($media->key).'/'.$media->name)->assertOk();
});

it('gives the thumbnail its own etag', function () {
    $media = storedMedia(['thumb_path' => 'media/thumbs/t.webp']);
    Storage::disk('local')->put('media/thumbs/t.webp', 'thumb-body');

    $mainEtag = $this->get($media->url())->headers->get('ETag');
    $thumbEtag = $this->get($media->thumbUrl())->headers->get('ETag');

    expect($thumbEtag)->not->toBe($mainEtag);

    $this->get($media->thumbUrl(), ['If-None-Match' => $thumbEtag])->assertStatus(304);
});

it('streams svg from a cloud disk without redirecting', function () {
    fakeCloudDisk('s3');
    config(['filesystems.disks.s3.url' => 'https://cdn.example.test']);
    $media = Media::factory()->create(['disk' => 's3', 'name' => 'l.svg', 'mime' => 'image/svg+xml', 'path' => 'media/2026/11/K-v1.svg']);
    Storage::disk('s3')->put($media->path, '<svg xmlns="http://www.w3.org/2000/svg"/>');

    $response = $this->get($media->url())->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->streamedContent())->toBe('<svg xmlns="http://www.w3.org/2000/svg"/>');
});

it('redirects a cloud pdf to a signed url carrying the real name', function () {
    fakeCloudDisk('s3');
    $seen = [];
    Storage::disk('s3')->buildTemporaryUrlsUsing(function ($path, $expiration, $options) use (&$seen) {
        $seen = compact('path', 'expiration', 'options');

        return 'https://signed.example.test/'.$path.'?sig=1';
    });
    $media = Media::factory()->document()->create(['disk' => 's3', 'name' => 'price list.pdf', 'path' => 'media/2026/11/K-v1.pdf']);

    $this->get($media->url())->assertStatus(302)->assertRedirect('https://signed.example.test/media/2026/11/K-v1.pdf?sig=1')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');

    expect($seen['options']['ResponseContentDisposition'])->toStartWith('inline')->toContain('price list.pdf')
        ->and($seen['expiration']->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();
});

it('redirects a cloud raster to the direct url when public, else to a signed url', function () {
    fakeCloudDisk('s3');
    config(['filesystems.disks.s3.url' => null]);
    Storage::disk('s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://signed.example.test/'.$path);
    $media = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/K-v1.webp']);

    $this->get($media->url())->assertRedirect('https://signed.example.test/media/2026/11/K-v1.webp');

    config(['filesystems.disks.s3.url' => 'https://cdn.example.test']);

    $this->get($media->appUrl())->assertStatus(302)
        ->assertRedirect(Storage::disk('s3')->url($media->path).'?v=1')
        ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, private');
});

it('answers range requests with 206 and 416', function () {
    $media = storedMedia(['name' => 'a.pdf', 'mime' => 'application/pdf', 'kind' => MediaKind::Document], '0123456789');

    $part = $this->get($media->url(), ['Range' => 'bytes=2-5'])->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 2-5/10')
        ->assertHeader('Accept-Ranges', 'bytes');
    expect($part->streamedContent())->toBe('2345');

    $tail = $this->get($media->url(), ['Range' => 'bytes=-3'])->assertStatus(206);
    expect($tail->streamedContent())->toBe('789');

    $this->get($media->url(), ['Range' => 'bytes=50-60'])->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */10');

    $this->get($media->url())->assertOk()->assertHeader('Accept-Ranges', 'bytes');
});

it('downloads a local file as an attachment with the real name', function () {
    $media = storedMedia(['name' => 'photo one.webp']);

    $response = $this->get($media->appUrl().'?download=1')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('photo one.webp');
});

it('downloads a cloud raster through a signed attachment url with the real name', function () {
    fakeCloudDisk('s3');
    config(['filesystems.disks.s3.url' => 'https://cdn.example.test']);
    $seen = [];
    Storage::disk('s3')->buildTemporaryUrlsUsing(function ($path, $expiration, $options) use (&$seen) {
        $seen = $options;

        return 'https://signed.example.test/'.$path;
    });
    $media = Media::factory()->create(['disk' => 's3', 'name' => 'photo.webp', 'path' => 'media/2026/11/K-v3.webp']);

    $this->get($media->appUrl().'?download=1')->assertRedirect('https://signed.example.test/media/2026/11/K-v3.webp');

    expect($seen['ResponseContentDisposition'])->toStartWith('attachment')->toContain('photo.webp');
});

it('does not cache partial or unsatisfiable responses', function () {
    $media = storedMedia(['name' => 'a.pdf', 'mime' => 'application/pdf', 'kind' => MediaKind::Document], '0123456789');

    $this->get($media->url(), ['Range' => 'bytes=0-1'])->assertStatus(206)->assertHeader('Cache-Control', 'no-store, private');
    $this->get($media->url(), ['Range' => 'bytes=99-'])->assertStatus(416)->assertHeader('Cache-Control', 'no-store, private');
});

it('handles open-ended, multiple, stale and empty suffix ranges', function () {
    $media = storedMedia(['name' => 'a.pdf', 'mime' => 'application/pdf', 'kind' => MediaKind::Document], '0123456789');

    expect($this->get($media->url(), ['Range' => 'bytes=7-'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 7-9/10')->streamedContent())->toBe('789');
    expect($this->get($media->url(), ['Range' => 'bytes=0-1,4-5'])->assertOk()->streamedContent())->toBe('0123456789');
    expect($this->get($media->url(), ['Range' => 'bytes=0-1', 'If-Range' => '"nope"'])->assertOk()->streamedContent())->toBe('0123456789');
    $this->get($media->url(), ['Range' => 'bytes=-0'])->assertStatus(416);
});

it('answers ranges on a cloud svg stream', function () {
    fakeCloudDisk('s3');
    $media = Media::factory()->create(['disk' => 's3', 'name' => 'l.svg', 'mime' => 'image/svg+xml', 'path' => 'media/2026/11/K-v1.svg']);
    Storage::disk('s3')->put($media->path, '0123456789');

    expect($this->get($media->url(), ['Range' => 'bytes=3-6'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 3-6/10')->streamedContent())->toBe('3456');
    $this->get($media->url(), ['Range' => 'bytes=20-'])->assertStatus(416);
});

it('answers HEAD without a body', function () {
    $media = storedMedia(['name' => 'a.pdf', 'mime' => 'application/pdf', 'kind' => MediaKind::Document], '0123456789');

    $this->call('HEAD', $media->url())->assertOk()->assertHeader('Content-Length', '10');
});
