<?php

use App\Models\User;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Support\Media\ServerCapabilities;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

/**
 * A file for Livewire's test upload, which needs the fake file's name property. In tests the temporary
 * upload reports the MIME type from the extension; content sniffing is covered by UploadMediaTest.
 */
function livewireUpload(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

function mediaUploader(): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create();
}

it('stores an uploaded file through the component and returns its tile data', function () {
    $this->actingAs(mediaUploader());

    $component = Livewire::test('hata::admin.media.index')
        ->assertSeeHtml('data-test="media-upload"')
        ->set('upload', livewireUpload('Hero photo.jpg', makeImage('jpg', 1200, 800)))
        ->set('uploadPickedSize', 9_000_000)
        ->set('uploadPickedExtension', 'heic')
        ->call('storeUpload');

    $media = Media::query()->sole();
    $result = $component->effects['returns'][0];

    expect($result)->toMatchArray(['ok' => true, 'id' => $media->id, 'name' => 'hero-photo.webp'])
        ->and($result['meta'])->toBe(__('hata::media.upload.done_compressed', [
            'before' => Media::formatBytes(9_000_000),
            'after' => $media->humanSize(),
        ]))
        ->and($media->original_size)->toBe(9_000_000)
        ->and($media->uploaded_extension)->toBe('heic');

    $component->assertSet('upload', null)
        ->assertSet('uploadPickedSize', null)
        ->assertSet('uploadPickedExtension', null)
        ->assertSee('hero-photo.webp');
});

it('returns the error text for a rejected file', function () {
    $this->actingAs(mediaUploader());

    $component = Livewire::test('hata::admin.media.index')
        ->set('upload', livewireUpload('x.psd', '8BPS fake photoshop file'))
        ->call('storeUpload');

    expect($component->effects['returns'][0])->toBe([
        'ok' => false,
        'error' => __('hata::media.errors.unsupported', ['name' => 'x.psd']),
    ])->and(Media::query()->count())->toBe(0);
});

it('stores uploads in the folder that was open when the file was queued', function () {
    $this->actingAs(mediaUploader());
    [$open, $queuedIn] = MediaFolder::factory()->count(2)->create()->all();

    Livewire::test('hata::admin.media.index')
        ->set('folder', (string) $open->id)
        ->set('upload', livewireUpload('a.jpg', makeImage()))
        ->set('uploadFolderId', $queuedIn->id)
        ->call('storeUpload')
        ->assertSet('uploadFolderId', null);

    expect(Media::query()->sole()->folder_id)->toBe($queuedIn->id);
});

it('stores uploads queued outside a folder without a folder', function () {
    $this->actingAs(mediaUploader());
    $open = MediaFolder::factory()->create();

    Livewire::test('hata::admin.media.index')
        ->set('folder', (string) $open->id)
        ->set('upload', livewireUpload('a.jpg', makeImage()))
        ->set('uploadFolderId', null)
        ->call('storeUpload');

    expect(Media::query()->sole()->folder_id)->toBeNull();
});

it('refuses an upload into a folder that no longer exists', function () {
    $this->actingAs(mediaUploader());

    $component = Livewire::test('hata::admin.media.index')
        ->set('upload', livewireUpload('a.jpg', makeImage()))
        ->set('uploadFolderId', 999_999)
        ->call('storeUpload');

    expect($component->effects['returns'][0])->toBe(['ok' => false, 'error' => __('hata::media.errors.folder_missing')])
        ->and(Media::query()->count())->toBe(0);
});

it('is forbidden to upload without media.upload', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    Livewire::test('hata::admin.media.index')
        ->assertDontSeeHtml('data-test="media-upload"')
        ->call('_startUpload', 'upload', [['name' => 'a.jpg', 'size' => 100, 'type' => 'image/jpeg']], false)
        ->assertForbidden();

    Livewire::test('hata::admin.media.index')
        ->call('storeUpload')
        ->assertForbidden();

    expect(Media::query()->count())->toBe(0);
});

it('passes the server upload cap to the uploader', function (?int $cap) {
    $capabilities = Mockery::mock(ServerCapabilities::class)->makePartial();
    $capabilities->shouldReceive('uploadCapBytes')->andReturn($cap);
    app()->instance(ServerCapabilities::class, $capabilities);
    $this->actingAs(mediaUploader());

    $config = Livewire::test('hata::admin.media.index')->instance()->uploaderConfig;

    expect($config)->toHaveKey('uploadCap', $cap);
})->with([
    '8 MB' => 8 * 1024 * 1024,
    'unlimited' => null,
]);

it('turns browser resizing off when optimization is off', function (bool $optimize, bool $clientResize, bool $expected) {
    config(['media.optimize.enabled' => $optimize, 'media.optimize.client_resize' => $clientResize]);
    $this->actingAs(mediaUploader());

    $config = Livewire::test('hata::admin.media.index')->instance()->uploaderConfig;

    expect($config['clientResize'])->toBe($expected);
})->with([
    'both on' => [true, true, true],
    'optimize off' => [false, true, false],
    'resize off' => [true, false, false],
]);
