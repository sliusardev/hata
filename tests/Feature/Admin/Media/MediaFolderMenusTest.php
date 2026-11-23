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

function menuUser(array $permissions): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', ...$permissions])->create();
}

it('gives every subfolder tile and tree row a folder menu with media.edit', function () {
    $this->actingAs(menuUser(['media.edit', 'media.delete']));
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    foreach (['grid', 'list'] as $view) {
        Livewire::test('hata::admin.media.index', ['folder' => (string) $parent->id, 'view' => $view])
            ->assertSeeHtml('data-test="media-subfolder-actions-'.$child->id.'"')
            ->assertSeeHtml('data-test="media-folder-actions-'.$child->id.'"')
            ->assertSeeHtml('data-test="media-folder-actions-'.$parent->id.'"')
            ->assertSeeHtml('confirmFolderDelete('.$child->id.')')
            ->assertSeeHtml('openRenameFolder('.$child->id.')')
            ->assertSeeHtml('openMoveFolder('.$child->id.')');
    }
});

it('hides the folder menus without media.edit', function () {
    $this->actingAs(menuUser([]));
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('hata::admin.media.index', ['folder' => (string) $parent->id])
        ->assertSeeHtml('data-test="media-subfolder-'.$child->id.'"')
        ->assertDontSeeHtml('data-test="media-subfolder-actions-'.$child->id.'"')
        ->assertDontSeeHtml('data-test="media-folder-actions-'.$child->id.'"')
        ->assertDontSeeHtml('openRenameFolder');
});

it('leaves out delete in the folder menus without media.delete', function () {
    $this->actingAs(menuUser(['media.edit']));
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('hata::admin.media.index', ['folder' => (string) $parent->id])
        ->assertSeeHtml('data-test="media-subfolder-actions-'.$child->id.'"')
        ->assertSeeHtml('openRenameFolder('.$child->id.')')
        ->assertDontSeeHtml('confirmFolderDelete');
});

it('deletes a subfolder from its tile and keeps the open folder open', function () {
    $this->actingAs(menuUser(['media.edit', 'media.delete']));
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    Livewire::test('hata::admin.media.index', ['folder' => (string) $parent->id])
        ->call('confirmFolderDelete', $child->id)
        ->assertSet('showFolderDeleteModal', true)
        ->call('deleteFolder')
        ->assertSet('folder', (string) $parent->id)
        ->assertSet('showFolderDeleteModal', false)
        ->assertDontSeeHtml('data-test="media-subfolder-'.$child->id.'"');

    expect(MediaFolder::query()->whereKey($child->id)->exists())->toBeFalse()
        ->and(MediaFolder::query()->whereKey($parent->id)->exists())->toBeTrue();
});

it('closes the details when the folder changes and the file is not in the new one', function () {
    $this->actingAs(menuUser(['media.edit']));
    [$inside, $outside] = MediaFolder::factory()->count(2)->create();
    $file = Media::factory()->inFolder($inside)->create();

    Livewire::test('hata::admin.media.index', ['folder' => (string) $inside->id])
        ->call('select', $file->id)
        ->set('folder', (string) $outside->id)
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('data-test="media-details"');
});

it('keeps the details open when the file is still shown', function () {
    $this->actingAs(menuUser(['media.edit']));
    $folder = MediaFolder::factory()->create();
    $file = Media::factory()->inFolder($folder)->create(['name' => 'hero-banner.webp']);

    Livewire::test('hata::admin.media.index')
        ->call('select', $file->id)
        ->set('search', 'hero')
        ->assertSet('selectedId', $file->id)
        ->set('folder', (string) $folder->id)
        ->assertSet('selectedId', $file->id);
});

it('closes the details when a filter excludes the file', function () {
    $this->actingAs(menuUser(['media.edit']));
    $file = Media::factory()->create(['name' => 'hero-banner.webp']);

    Livewire::test('hata::admin.media.index')
        ->call('select', $file->id)
        ->set('search', 'zzz-nothing')
        ->assertSet('selectedId', null);
});

it('closes the details when the file is moved out of the open folder', function () {
    $this->actingAs(menuUser(['media.edit']));
    [$open, $other] = MediaFolder::factory()->count(2)->create();
    $file = Media::factory()->inFolder($open)->create();

    Livewire::test('hata::admin.media.index', ['folder' => (string) $open->id])
        ->call('select', $file->id)
        ->call('moveTo', [$file->id], $other->id)
        ->assertSet('selectedId', null);

    $kept = Media::factory()->inFolder($open)->create();

    Livewire::test('hata::admin.media.index')
        ->call('select', $kept->id)
        ->call('moveTo', [$kept->id], $other->id)
        ->assertSet('selectedId', $kept->id);
});

it('asks before closing details with unsaved changes when the file leaves view', function () {
    $this->actingAs(menuUser(['media.edit']));
    [$inside, $outside] = MediaFolder::factory()->count(2)->create();
    $file = Media::factory()->inFolder($inside)->create();

    Livewire::test('hata::admin.media.index', ['folder' => (string) $inside->id])
        ->call('select', $file->id)
        ->set('details.title', 'Unsaved')
        ->set('folder', (string) $outside->id)
        ->assertSet('selectedId', $file->id)
        ->assertSet('showDiscardModal', true)
        ->call('discardChanges')
        ->assertSet('selectedId', null);

    expect($file->refresh()->title)->toBeNull();
});

it('closes the details after saving a folder change that takes the file out of the open folder', function () {
    $this->actingAs(menuUser(['media.edit']));
    [$open, $other] = MediaFolder::factory()->count(2)->create();
    $file = Media::factory()->inFolder($open)->create();

    Livewire::test('hata::admin.media.index', ['folder' => (string) $open->id])
        ->call('select', $file->id)
        ->set('details.folder_id', (string) $other->id)
        ->call('saveDetails')
        ->assertSet('selectedId', null)
        ->assertSet('showDiscardModal', false);

    expect($file->refresh()->folder_id)->toBe($other->id);
});

it('keeps dirty details open silently when a search excludes the file', function () {
    $this->actingAs(menuUser(['media.edit']));
    $file = Media::factory()->create(['name' => 'hero-banner.webp']);

    Livewire::test('hata::admin.media.index')
        ->call('select', $file->id)
        ->set('details.title', 'Unsaved')
        ->set('search', 'zzz-nothing')
        ->assertSet('selectedId', $file->id)
        ->assertSet('showDiscardModal', false);
});

it('drags subfolder tiles by their open control, not the wrapper holding the menu', function () {
    $this->actingAs(menuUser(['media.edit']));
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);

    $html = Livewire::test('hata::admin.media.index', ['folder' => (string) $parent->id])->html();

    expect($html)->toContain('data-test="media-subfolder-open-'.$child->id.'"');
    preg_match('/<div[^>]*data-test="media-subfolder-'.$child->id.'"[^>]*>/s', $html, $wrapper);
    expect($wrapper[0])->not->toContain('draggable');
});
