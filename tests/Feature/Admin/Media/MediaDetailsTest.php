<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

function mediaEditor(array $extra = ['media.delete']): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', ...$extra])->create();
}

it('opens the details of a file', function () {
    $uploader = User::factory()->create(['name' => 'Olena Koval']);
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create([
        'name' => 'hero-kyiv-autumn.webp',
        'original_path' => 'media/originals/hero.jpg',
        'uploaded_by' => $uploader->id,
        'size' => 167_936,
        'width' => 2560,
        'height' => 1707,
    ]);

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSet('selectedId', $media->id)
        ->assertSeeHtml('data-test="media-details"')
        ->assertSee(__('media.details.type_converted', ['type' => 'WEBP', 'original' => 'JPG']))
        ->assertSee('164 KB · 2560 × 1707')
        ->assertSee('Olena Koval')
        ->assertSee(__('media.index.nowhere'))
        ->assertSee($media->url());
});

it('ignores a file id that does not exist', function () {
    $this->actingAs(mediaEditor());

    Livewire::test('pages::admin.media.index')
        ->call('select', 999_999)
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('data-test="media-details"');
});

it('does not let the client set the open file directly', function () {
    $this->actingAs(mediaEditor());

    Livewire::test('pages::admin.media.index')->set('selectedId', 5);
})->throws(CannotUpdateLockedPropertyException::class);

it('saves alt text per language and the title', function () {
    $this->actingAs(mediaEditor());
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('details.alt.uk', 'Осінній Київ')
        ->set('details.alt.en', 'Autumn Kyiv')
        ->set('details.title', 'Autumn')
        ->set('details.folder_id', (string) $folder->id)
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertDispatched('toast-show');

    $media->refresh();

    expect($media->alt)->toEqual(['uk' => 'Осінній Київ', 'en' => 'Autumn Kyiv'])
        ->and($media->title)->toBe('Autumn')
        ->and($media->folder_id)->toBe($folder->id);
});

it('shows a field error for an invalid title', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('details.title', str_repeat('a', 256))
        ->call('saveDetails')
        ->assertHasErrors('details.title');

    expect($media->refresh()->title)->toBeNull();
});

it('refuses to save details without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertDontSeeHtml('data-test="media-details-save"')
        ->set('details.title', 'Changed')
        ->call('saveDetails')
        ->assertDispatched('toast-show');

    expect($media->refresh()->title)->toBeNull();
});

it('asks before discarding unsaved changes when another file is opened', function () {
    $this->actingAs(mediaEditor());
    [$first, $second] = Media::factory()->count(2)->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $first->id)
        ->set('details.title', 'Unsaved')
        ->call('select', $second->id)
        ->assertSet('selectedId', $first->id)
        ->assertSet('showDiscardModal', true)
        ->call('discardChanges')
        ->assertSet('selectedId', $second->id)
        ->assertSet('details.title', '');

    expect($first->refresh()->title)->toBeNull();
});

it('asks before deleting and deletes after confirming', function () {
    $this->actingAs(mediaEditor());
    $media = mediaWithFiles(['name' => 'price-list.pdf']);
    MediaUsage::query()->create(['media_id' => $media->id, 'usable_type' => User::class, 'usable_id' => 1, 'field' => 'cover']);

    $component = Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->call('confirmDelete', $media->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingIds', [$media->id])
        ->assertSee(__('media.delete.body_one', ['name' => 'price-list.pdf']))
        ->assertSee(trans_choice('media.delete.used', 1));

    $this->assertModelExists($media);

    $component->call('deleteConfirmed')
        ->assertSet('showDeleteModal', false)
        ->assertSet('selectedId', null)
        ->assertDispatched('toast-show');

    $this->assertModelMissing($media);
    Storage::disk('local')->assertMissing($media->path);
});

it('hides delete without media.delete', function () {
    $this->actingAs(mediaEditor([]));
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertDontSeeHtml('data-test="media-details-delete"')
        ->call('confirmDelete', $media->id)
        ->assertForbidden();

    $this->assertModelExists($media);
});

it('shows the optimization result', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create([
        'original_size' => 19_293_798,
        'size' => 167_936,
        'optimized_with' => 'webp:2560:80:exif0',
    ]);

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSee('18.4 MB → 164 KB (−99%)')
        ->assertDontSee(__('media.details.not_optimized'));

    app()->setLocale('uk');

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSee('18,4 MB → 164 KB (−99%)');
});

it('says when an image was too large to optimize', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->image()->create(['optimized_with' => null]);
    $document = Media::factory()->document()->create();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSee(__('media.details.not_optimized'))
        ->call('select', $document->id)
        ->assertDontSee(__('media.details.not_optimized'));
});
