<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

function folderManager(array $extra = ['media.delete']): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', ...$extra])->create();
}

it('creates, renames and refuses duplicate folders', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create(['name' => 'Marketing']);

    $component = Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id])
        ->call('createFolder', 'Banners')
        ->assertHasNoErrors();

    $banners = MediaFolder::query()->where('name', 'Banners')->sole();
    expect($banners->parent_id)->toBe($parent->id);

    $component->call('createFolder', ' Banners ')
        ->assertHasErrors(['folderName' => __('media.errors.folder_exists')]);

    expect(MediaFolder::query()->where('name', 'Banners')->count())->toBe(1);

    $component->call('renameFolder', $banners->id, 'Social')
        ->assertHasNoErrors();

    expect($banners->refresh()->name)->toBe('Social');

    MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Ads']);

    $component->call('renameFolder', $banners->id, 'Ads')
        ->assertHasErrors('folderName');

    expect($banners->refresh()->name)->toBe('Social');
});

it('hides folder management without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $folder = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index', ['folder' => (string) $folder->id])
        ->assertDontSeeHtml('data-test="media-new-folder"')
        ->assertDontSeeHtml('data-test="media-folder-actions"')
        ->call('createFolder', 'Nope')
        ->assertDispatched('toast-show');

    expect(MediaFolder::query()->count())->toBe(1);
});

it('requires typing the folder name when it holds more than 10 files', function () {
    $this->actingAs(folderManager());
    $folder = MediaFolder::factory()->create(['name' => 'Archive']);
    Media::factory()->count(11)->inFolder($folder)->create();

    Livewire::test('pages::admin.media.index')
        ->call('confirmFolderDelete', $folder->id)
        ->assertSet('showFolderDeleteModal', true)
        ->assertSee(__('media.folders.delete.type_name', ['name' => 'Archive']))
        ->set('confirmName', 'Archiv')
        ->call('deleteFolder')
        ->assertHasErrors('confirmName');

    $this->assertModelExists($folder);
    expect(Media::query()->count())->toBe(11);
});

it('deletes a folder with everything inside after confirmation', function () {
    $this->actingAs(folderManager());
    $folder = MediaFolder::factory()->create(['name' => 'Archive']);
    $child = MediaFolder::factory()->create(['parent_id' => $folder->id]);
    $file = mediaWithFiles(['folder_id' => $child->id]);
    Media::factory()->count(10)->inFolder($folder)->create();
    $outside = Media::factory()->create();

    $component = Livewire::test('pages::admin.media.index', ['folder' => (string) $child->id])
        ->call('confirmFolderDelete', $folder->id)
        ->assertSee(__('media.folders.delete.summary', [
            'files' => trans_choice('media.index.file_count', 11),
            'folders' => trans_choice('media.folders.delete.subfolders', 1),
        ]));

    $this->assertModelExists($folder);

    $component->set('confirmName', 'Archive')
        ->call('deleteFolder')
        ->assertHasNoErrors()
        ->assertSet('showFolderDeleteModal', false)
        ->assertSet('folder', null)
        ->assertDispatched('toast-show');

    $this->assertModelMissing($folder);
    $this->assertModelMissing($child);
    $this->assertModelMissing($file);
    $this->assertModelExists($outside);
    Storage::disk('local')->assertMissing($file->path);
});

it('refuses to delete a folder without media.delete', function () {
    $this->actingAs(folderManager([]));
    $folder = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('confirmFolderDelete', $folder->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet('showFolderDeleteModal', false);

    $this->assertModelExists($folder);
});

it('shows a forbidden toast instead of a 403 when a dialog may not open', function (string $method, string $modal) {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();
    $argument = ['confirmDelete' => $media->id, 'openNewFolder' => null][$method] ?? $folder->id;

    Livewire::test('pages::admin.media.index')
        ->call($method, ...array_filter([$argument]))
        ->assertOk()
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet($modal, false);
})->with([
    ['confirmDelete', 'showDeleteModal'],
    ['confirmFolderDelete', 'showFolderDeleteModal'],
    ['openNewFolder', 'showFolderModal'],
    ['openRenameFolder', 'showFolderModal'],
]);

it('moves files by drop', function () {
    $this->actingAs(folderManager());
    $folder = MediaFolder::factory()->create();
    [$first, $second] = Media::factory()->count(2)->create();
    $untouched = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('moveTo', [$first->id, $second->id], $folder->id)
        ->assertDispatched('toast-show');

    expect($first->refresh()->folder_id)->toBe($folder->id)
        ->and($second->refresh()->folder_id)->toBe($folder->id)
        ->and($untouched->refresh()->folder_id)->toBeNull();

    Livewire::test('pages::admin.media.index')->call('moveTo', [$first->id], null);

    expect($first->refresh()->folder_id)->toBeNull();
});

it('refuses to move files without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('moveTo', [$media->id], $folder->id)
        ->assertDispatched('toast-show');

    expect($media->refresh()->folder_id)->toBeNull();
});

it('takes the dropped files out of the selection', function () {
    $this->actingAs(folderManager());
    $folder = MediaFolder::factory()->create();
    [$moved, $kept] = Media::factory()->count(2)->create()->all();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$moved->id, $kept->id])
        ->call('moveTo', [(string) $moved->id], $folder->id)
        ->assertSet('selected', [$kept->id]);
});
