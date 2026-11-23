<?php

use App\Models\User;
use Hata\Actions\Media\ZipMedia;
use Hata\Models\Media;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

it('downloads the given files as a zip', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $first = mediaWithFiles(['name' => 'a.webp']);
    $second = mediaWithFiles(['name' => 'b.pdf']);
    Storage::disk('local')->put($first->path, 'first-bytes');

    $response = $this->get(route('admin.media.zip', ['ids' => [$first->id, (string) $second->id, 999_999, 'x']]))
        ->assertOk()
        ->assertDownload('media.zip');

    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());

    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('a.webp'))->toBe('first-bytes')
        ->and($zip->getFromName('b.pdf'))->toBe('data');

    $zip->close();
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('is forbidden without media.view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $media = mediaWithFiles();

    $this->get(route('admin.media.zip', ['ids' => [$media->id]]))->assertForbidden();
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('finds nothing to zip for ids that do not exist', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $this->get(route('admin.media.zip', ['ids' => [999_999]]))->assertNotFound();
    $this->get(route('admin.media.zip'))->assertNotFound();
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('refuses more files than one archive holds', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $ids = Media::factory()->count(ZipMedia::MAX_FILES + 1)->create()->modelKeys();

    $this->get(route('admin.media.zip', ['ids' => $ids]))->assertStatus(422);
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('finds nothing to zip when the zip extension is missing', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $this->get(route('admin.media.zip', ['ids' => [mediaWithFiles()->id]]))->assertNotFound();
})->skip(class_exists(ZipArchive::class), 'Runs only on a PHP without the zip extension.');
