<?php

use App\Models\User;
use Hata\Enums\MediaKind;
use Hata\Models\Media;
use Hata\Support\Media\MemoryGuard;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.edit'])->create());
});

/**
 * @param  array<string, mixed>  $attributes
 */
function previewMedia(string $current, ?string $original = null, array $attributes = []): Media
{
    $media = Media::factory()->create($attributes);
    $media->forceFill(['original_path' => $original === null ? null : "media/originals/{$media->key}.jpg"])->save();

    Storage::disk('local')->put($media->path, $current);

    if ($original !== null) {
        Storage::disk('local')->put((string) $media->original_path, $original);
    }

    return $media;
}

function previewJpeg(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

it('serves a downscaled oriented preview of the original', function () {
    $media = previewMedia(previewJpeg(100, 100), previewJpeg(3000, 2000));

    $response = $this->get(route('admin.media.source', $media))->assertOk();
    [$width, $height] = getimagesizefromstring((string) $response->getContent());

    expect($width)->toBe(2048)
        ->and($height)->toBeGreaterThanOrEqual(1364)->toBeLessThanOrEqual(1366)
        ->and($response->headers->get('Content-Type'))->toBe('image/jpeg')
        ->and($response->headers->get('X-Source-Width'))->toBe('3000')
        ->and($response->headers->get('X-Source-Height'))->toBe('2000')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('does not scale a small source up', function () {
    $media = previewMedia(previewJpeg(100, 80), previewJpeg(640, 480));

    $response = $this->get(route('admin.media.source', $media))->assertOk();

    expect(getimagesizefromstring((string) $response->getContent())[0])->toBe(640);
});

it('falls back to the current file without an original', function () {
    $media = previewMedia(previewJpeg(300, 200), attributes: ['mime' => 'image/jpeg', 'name' => 'a.jpg']);

    $response = $this->get(route('admin.media.source', $media))->assertOk();

    expect(getimagesizefromstring((string) $response->getContent())[0])->toBe(300)
        ->and($response->headers->get('X-Source-Width'))->toBe('300');
});

it('serves a png preview for a source with alpha', function () {
    $image = imagecreatetruecolor(50, 40);
    imagesavealpha($image, true);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $media = previewMedia($png, attributes: ['mime' => 'image/png', 'name' => 'a.png']);

    $this->get(route('admin.media.source', $media))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png');
});

it('is forbidden without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $media = previewMedia(previewJpeg(100, 100));

    $this->get(route('admin.media.source', $media))->assertForbidden();
});

it('returns 404 for documents and svg', function () {
    $document = previewMedia('data', attributes: ['kind' => MediaKind::Document, 'mime' => 'application/pdf', 'name' => 'a.pdf']);
    $svg = previewMedia('<svg xmlns="http://www.w3.org/2000/svg"/>', attributes: ['mime' => 'image/svg+xml', 'name' => 'a.svg']);

    $this->get(route('admin.media.source', $document))->assertNotFound();
    $this->get(route('admin.media.source', $svg))->assertNotFound();
});

it('refuses an image too large for the memory limit', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(1));
    $media = previewMedia(previewJpeg(100, 100));

    $this->get(route('admin.media.source', $media))
        ->assertStatus(422)
        ->assertSee(__('media.errors.too_large_to_edit'));
});
