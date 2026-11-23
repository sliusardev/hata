<?php

use App\Models\User;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
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

it('moves a folder through the page and updates the tree order', function () {
    $this->actingAs(folderManager());
    $a = MediaFolder::factory()->create(['name' => 'A']);
    $b = MediaFolder::factory()->create(['name' => 'B']);

    $component = Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $b->id, $a->id)
        ->assertHasNoErrors();

    expect($b->refresh()->parent_id)->toBe($a->id);

    $rows = collect($component->get('folders'))->map(fn ($row) => [$row['folder']->id, $row['depth']])->all();
    expect($rows)->toBe([[$a->id, 0], [$b->id, 1]]);

    $component->call('moveFolder', $b->id, null);
    expect($b->refresh()->parent_id)->toBeNull();
});

it('shows a danger toast when a folder is moved into its descendant', function () {
    $this->actingAs(folderManager());
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $a->id, $b->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === __('media.errors.folder_into_itself'));

    expect($a->refresh()->parent_id)->toBeNull();
});

it('shows a danger toast when the target already has a folder with that name', function () {
    $this->actingAs(folderManager());
    $target = MediaFolder::factory()->create(['name' => 'Target']);
    MediaFolder::factory()->create(['parent_id' => $target->id, 'name' => 'Same']);
    $moving = MediaFolder::factory()->create(['name' => 'Same']);

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $moving->id, $target->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === __('media.errors.folder_exists'));

    expect($moving->refresh()->parent_id)->toBeNull();
});

it('does not show a success toast when a folder is moved to its current parent', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $child->id, $parent->id)
        ->assertNotDispatched('toast-show');

    expect($child->refresh()->parent_id)->toBe($parent->id);
});

it('renders chevrons for parents, full-name titles and draggable folders only with media.edit', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create(['name' => 'Parent folder']);
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Child']);

    Livewire::test('pages::admin.media.index')
        ->assertSeeHtml('data-test="media-folder-toggle-'.$parent->id.'"')
        ->assertDontSeeHtml('data-test="media-folder-toggle-'.$child->id.'"')
        ->assertSeeHtml('title="Parent folder"')
        ->assertSeeHtml('draggable="true"');

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    Livewire::test('pages::admin.media.index')
        ->assertDontSeeHtml('draggable="true"')
        ->assertSeeHtml('title="Parent folder"');
});

it('ignores a missing folder or target when moving a folder', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    $a = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $a->id, 9999)
        ->call('moveFolder', 9999, null)
        ->assertHasNoErrors()
        ->assertNotDispatched('toast-show');

    expect($a->refresh()->parent_id)->toBe($parent->id);
});

it('refuses a crafted folder move without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $a->id, $b->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    expect($a->refresh()->parent_id)->toBeNull();
});

it('expands the target after a folder is moved into it', function () {
    $this->actingAs(folderManager());
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('moveFolder', $a->id, $b->id)
        ->assertSet('expandFolderId', $b->id)
        ->call('moveFolder', $a->id, null)
        ->assertSet('expandFolderId', null);
});

it('casts parent_id to an integer', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);

    expect(MediaFolder::query()->find($b->id)->parent_id)->toBeInt();
});

it('moves a folder from the dialog without offering the folder or its descendants', function () {
    $this->actingAs(folderManager());
    $a = MediaFolder::factory()->create(['name' => 'Alpha']);
    $child = MediaFolder::factory()->create(['parent_id' => $a->id, 'name' => 'Child']);
    $other = MediaFolder::factory()->create(['name' => 'Omega']);

    $component = Livewire::test('pages::admin.media.index')
        ->call('openMoveFolder', $a->id)
        ->assertSet('showMoveFolderModal', true);

    $ids = array_column($component->get('moveFolderTargets'), 'id');
    expect($ids)->toContain($other->id)->not->toContain($a->id)->not->toContain($child->id);

    $component->assertSet('moveFolderTarget', '')
        ->assertSee('Move “Alpha”', false)
        ->assertSee('Top level');

    $component->set('moveFolderTarget', (string) $other->id)
        ->call('moveFolderConfirmed')
        ->assertSet('showMoveFolderModal', false)
        ->assertSet('movingFolderId', null);

    expect($a->refresh()->parent_id)->toBe($other->id);
});

it('marks the current parent in the move dialog and does not move without a choice', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create(['name' => 'Parent']);
    $a = MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Alpha']);

    $component = Livewire::test('pages::admin.media.index')
        ->call('openMoveFolder', $a->id)
        ->assertSee('Now in: Parent')
        ->assertSeeHtml('data-test="media-folder-move-'.$parent->id.'"')
        ->assertSeeHtml('value="'.$parent->id.'" disabled')
        ->assertSee('current')
        ->call('moveFolderConfirmed')
        ->assertSet('showMoveFolderModal', true);

    expect($a->refresh()->parent_id)->toBe($parent->id);

    $component->set('moveFolderTarget', 'top')->call('moveFolderConfirmed');

    expect($a->refresh()->parent_id)->toBeNull();
});

it('forgets the folder being moved when the dialog is cancelled', function () {
    $this->actingAs(folderManager());
    $a = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('openMoveFolder', $a->id)
        ->call('cancelPending')
        ->assertSet('movingFolderId', null)
        ->assertSet('showMoveFolderModal', false);
});

it('does not open the move dialog without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $a = MediaFolder::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('openMoveFolder', $a->id)
        ->assertSet('showMoveFolderModal', false);
});

it('lists the direct subfolders of the open folder by name with their counts', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create(['name' => 'Marketing']);
    $zeta = MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Zeta']);
    $alpha = MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Alpha']);
    MediaFolder::factory()->create(['parent_id' => $alpha->id, 'name' => 'Deep']);
    Media::factory()->count(2)->inFolder($alpha)->create();
    Media::factory()->inFolder($parent)->create();

    $component = Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id]);
    $tiles = $component->get('subfolders');

    expect($tiles->pluck('name')->all())->toBe(['Alpha', 'Zeta'])
        ->and($tiles->first()->media_count)->toBe(2)
        ->and($tiles->first()->children_count)->toBe(1)
        ->and($tiles->last()->children_count)->toBe(0);

    $component->assertSeeHtml('data-test="media-subfolder-'.$zeta->id.'"')
        ->assertSeeHtml('title="Zeta"')
        ->assertSeeHtml('data-test="media-folder-'.$zeta->id.'"')
        ->assertSeeHtml('title="Marketing"');
});

it('shows no subfolder tiles for all files, no folder or while a filter is active', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    MediaFolder::factory()->create(['parent_id' => $parent->id]);

    expect(Livewire::test('pages::admin.media.index')->get('subfolders'))->toBeEmpty()
        ->and(Livewire::test('pages::admin.media.index', ['folder' => 'none'])->get('subfolders'))->toBeEmpty();

    $open = Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id]);
    expect($open->get('subfolders'))->toHaveCount(1);

    foreach ([['search', 'x'], ['type', 'image'], ['month', '2026-01'], ['unused', true]] as [$property, $value]) {
        expect($open->set($property, $value)->get('subfolders'))->toBeEmpty();
        $open->set($property, $property === 'unused' ? false : '');
    }
});

it('shows the tiles instead of the empty state in a folder that only has subfolders', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id, 'name' => 'Banners']);

    Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id])
        ->assertSee('Banners')
        ->assertSee(__('media.index.subfolders.no_files'))
        ->assertDontSee(__('media.index.empty.title'))
        ->assertDontSee(__('media.index.no_results.title'))
        ->set('view', 'list')
        ->assertSeeHtml('data-test="media-subfolder-'.$child->id.'"')
        ->assertSee(__('media.index.subfolders.no_files'));
});

it('opens a subfolder from its tile', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id])
        ->set('folder', (string) $child->id)
        ->assertSet('folder', (string) $child->id);
});

it('expands the open folder in the tree after a subfolder is created in it', function () {
    $this->actingAs(folderManager());
    $parent = MediaFolder::factory()->create();
    $other = MediaFolder::factory()->create();

    $component = Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id])
        ->assertSet('expandFolderId', null)
        ->call('createFolder', 'Banners')
        ->assertSet('expandFolderId', $parent->id)
        ->call('createFolder', ' ')
        ->assertSet('expandFolderId', $parent->id);

    $component->set('folder', (string) $other->id)->assertSet('expandFolderId', null);

    Livewire::test('pages::admin.media.index', ['folder' => (string) $parent->id])
        ->call('createFolder', 'Banners')
        ->assertSet('expandFolderId', null);
});
