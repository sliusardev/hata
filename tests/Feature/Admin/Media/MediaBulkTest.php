<?php

use App\Actions\Media\ZipMedia;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

function mediaManager(array $permissions = ['media.delete']): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', ...$permissions])->create();
}

it('bulk deletes after confirmation', function () {
    $this->actingAs(mediaManager());
    [$first, $second, $kept] = [mediaWithFiles(), mediaWithFiles(), mediaWithFiles()];
    MediaUsage::query()->create(['media_id' => $first->id, 'usable_type' => User::class, 'usable_id' => 1, 'field' => 'cover']);

    $component = Livewire::test('pages::admin.media.index')
        ->set('view', 'list')
        ->set('selected', [(string) $first->id, (string) $second->id, '999999', 'x'])
        ->assertSeeHtml('data-test="media-bulk-bar"')
        ->call('confirmBulkDelete')
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingIds', [$first->id, $second->id])
        ->assertSee(__('media.delete.body_many', ['files' => trans_choice('media.index.file_count', 2)]))
        ->assertSee(trans_choice('media.delete.used_files', 1, ['total' => 2]));

    $this->assertModelExists($first);

    $component->call('deleteConfirmed')
        ->assertSet('selected', [])
        ->assertDispatched('toast-show');

    $this->assertModelMissing($first);
    $this->assertModelMissing($second);
    $this->assertModelExists($kept);
    Storage::disk('local')->assertMissing([$first->path, $second->path]);
    Storage::disk('local')->assertExists($kept->path);
});

it('refuses a bulk delete without media.delete', function () {
    $this->actingAs(mediaManager([]));
    $media = mediaWithFiles();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$media->id])
        ->assertDontSeeHtml('data-test="media-bulk-delete"')
        ->call('confirmBulkDelete')
        ->assertOk()
        ->assertSet('showDeleteModal', false)
        ->assertSet('deletingIds', [])
        ->assertDispatched('toast-show');

    $this->assertModelExists($media);
});

it('selects tiles in the grid while selecting several', function () {
    $this->actingAs(mediaManager());
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('toggleSelected', $media->id)
        ->assertSet('selected', [$media->id])
        ->call('toggleSelected', 999_999)
        ->assertSet('selected', [$media->id])
        ->call('toggleSelected', $media->id)
        ->assertSet('selected', []);
});

it('selects every file on the list page at once', function () {
    $this->actingAs(mediaManager());
    $ids = Media::factory()->count(3)->create()->pluck('id')->sort()->values()->all();

    $component = Livewire::test('pages::admin.media.index')
        ->set('view', 'list')
        ->call('togglePageSelection');

    expect(collect($component->get('selected'))->sort()->values()->all())->toBe($ids);

    $component->call('togglePageSelection')->assertSet('selected', []);
});

it('clears the selection when the filters change', function () {
    $this->actingAs(mediaManager());
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('toggleSelected', $media->id)
        ->set('search', 'x')
        ->assertSet('selected', []);
});

it('sends the zip download to the zip route with the selected ids', function () {
    $this->actingAs(mediaManager());
    $first = Media::factory()->create();
    $second = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$first->id, $second->id])
        ->assertSeeHtml('data-test="media-bulk-zip"')
        ->call('downloadZip')
        ->assertRedirect(route('admin.media.zip', ['ids' => [$first->id, $second->id]]));
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');

it('refuses a zip with more files than the cap', function () {
    $this->actingAs(mediaManager());
    $ids = Media::factory()->count(ZipMedia::MAX_FILES + 1)->create()->modelKeys();

    Livewire::test('pages::admin.media.index')
        ->set('selected', $ids)
        ->call('downloadZip')
        ->assertNoRedirect()
        ->assertDispatched('toast-show');
})->skip(! class_exists(ZipArchive::class), 'ZipArchive is not installed.');
