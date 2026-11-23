<?php

use App\Models\User;
use Hata\Actions\Media\UploadMedia;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Hata\Support\Media\MemoryGuard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
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
        'uploaded_extension' => 'jpg',
        'uploaded_by' => $uploader->id,
        'size' => 167_936,
        'width' => 2560,
        'height' => 1707,
    ]);

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSet('selectedId', $media->id)
        ->assertSeeHtml('data-test="media-details"')
        ->assertSee(__('hata::media.details.type_converted', ['type' => 'WEBP', 'original' => 'JPG']))
        ->assertSee('164 KB · 2560 × 1707')
        ->assertSee('Olena Koval')
        ->assertSee(__('hata::media.index.nowhere'))
        ->assertSee($media->appUrl());
});

it('ignores a file id that does not exist', function () {
    $this->actingAs(mediaEditor());

    Livewire::test('hata::admin.media.index')
        ->call('select', 999_999)
        ->assertSet('selectedId', null)
        ->assertDontSeeHtml('data-test="media-details"');
});

it('does not let the client set the open file directly', function () {
    $this->actingAs(mediaEditor());

    Livewire::test('hata::admin.media.index')->set('selectedId', 5);
})->throws(CannotUpdateLockedPropertyException::class);

it('saves alt text per language and the title', function () {
    $this->actingAs(mediaEditor());
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();

    Livewire::test('hata::admin.media.index')
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

it('shows the primary alt text and folds the other languages into translations', function () {
    config([
        'hata.content.interface_default' => 'uk',
        'hata.admin.interface_locales' => ['en' => 'English', 'uk' => 'Українська', 'pl' => 'Polski', 'de' => 'Deutsch'],
    ]);
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create(['alt' => ['uk' => 'Каша в мисці', 'pl' => 'Kaszka w misce']]);

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSet('details.alt.uk', 'Каша в мисці')
        ->assertSet('details.alt.de', '')
        ->assertSeeHtml('id="alt-uk"')
        ->assertSeeHtml('data-test="alt-translations"')
        ->assertSee(__('hata::media.details.translations_filled', ['filled' => 1, 'total' => 3]))
        ->assertSeeHtml('placeholder="Каша в мисці"');
});

it('opens the full file in a new tab from the preview and the open button', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();
    $url = e($media->url());

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtmlInOrder(['href="'.$url.'"', 'target="_blank"', 'data-test="media-details-preview"'])
        ->assertSeeHtmlInOrder(['data-test="media-details-preview"', 'href="'.$url.'"', 'target="_blank"', 'data-test="media-details-open"'])
        ->assertSee(__('hata::media.details.open'));
});

it('shows no translations block with a single language', function () {
    config(['hata.content.interface_default' => 'en', 'hata.admin.interface_locales' => ['en' => 'English']]);
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create(['alt' => ['en' => 'Bowl']]);

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('id="alt-en"')
        ->assertDontSeeHtml('data-test="alt-translations"');
});

it('shows a field error for an invalid title', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->set('details.title', str_repeat('a', 256))
        ->call('saveDetails')
        ->assertHasErrors('details.title');

    expect($media->refresh()->title)->toBeNull();
});

it('refuses to save details without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $media = Media::factory()->create();

    Livewire::test('hata::admin.media.index')
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

    Livewire::test('hata::admin.media.index')
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

    $component = Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->call('confirmDelete', $media->id)
        ->assertSet('showDeleteModal', true)
        ->assertSet('deletingIds', [$media->id])
        ->assertSee(__('hata::media.delete.body_one', ['name' => 'price-list.pdf']))
        ->assertSee(trans_choice('hata::media.delete.used', 1));

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

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertDontSeeHtml('data-test="media-details-delete"')
        ->call('confirmDelete', $media->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet('showDeleteModal', false);

    $this->assertModelExists($media);
});

it('shows the optimization result', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create([
        'original_size' => 19_293_798,
        'size' => 167_936,
        'optimized_with' => 'webp:2560:80:exif0',
    ]);

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSee('18.4 MB → 164 KB (−99%)')
        ->assertDontSee(__('hata::media.details.not_optimized'));

    app()->setLocale('uk');

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSee('18,4 MB → 164 KB (−99%)');
});

it('says why an image was not optimized', function () {
    $this->actingAs(mediaEditor());
    $tooLarge = Media::factory()->image()->create(['optimized_with' => null, 'optimize_failure' => 'too_large']);
    $unreadable = Media::factory()->image()->create(['optimized_with' => null, 'optimize_failure' => 'unreadable']);
    $animated = Media::factory()->create(['name' => 'spinner.gif', 'mime' => 'image/gif', 'optimized_with' => null, 'optimize_failure' => null]);

    Livewire::test('hata::admin.media.index')
        ->call('select', $tooLarge->id)
        ->assertSee(__('hata::media.details.not_optimized'))
        ->assertDontSee(__('hata::media.details.unreadable'))
        ->call('select', $unreadable->id)
        ->assertSee(__('hata::media.details.unreadable'))
        ->assertDontSee(__('hata::media.details.not_optimized'))
        ->call('select', $animated->id)
        ->assertDontSee(__('hata::media.details.not_optimized'))
        ->assertDontSee(__('hata::media.details.unreadable'));
});

it('shows the panel line for an upload the server was too short of memory to optimize', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create());
    app()->instance(MemoryGuard::class, new MemoryGuard(limitBytes: 1024 * 1024));
    $media = app(UploadMedia::class)->handle(auth()->user(), uploadedImage('big.jpg', makeImage('jpg', 2000, 2000)));

    expect($media->optimize_failure)->toBe('too_large');

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSee(__('hata::media.details.not_optimized'));
});

it('says which format a file was uploaded as without a kept original', function () {
    config(['hata.media.optimize.keep_originals' => false]);
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create());
    $media = app(UploadMedia::class)->handle(auth()->user(), uploadedImage('photo.jpg', makeImage('jpg', 400, 300)));

    expect($media->original_path)->toBeNull()
        ->and($media->extension())->toBe('WEBP');

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSee(__('hata::media.details.type_converted', ['type' => 'WEBP', 'original' => 'JPG']));
});

it('clears a pending confirmation when the dialog is cancelled', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();
    $folder = MediaFolder::factory()->create();

    Livewire::test('hata::admin.media.index')
        ->call('confirmDelete', $media->id)
        ->call('cancelPending')
        ->assertSet('deletingIds', [])
        ->assertSet('showDeleteModal', false)
        ->call('deleteConfirmed')
        ->call('confirmFolderDelete', $folder->id)
        ->call('cancelPending')
        ->assertSet('deletingFolderId', null)
        ->call('deleteFolder');

    $this->assertModelExists($media);
    $this->assertModelExists($folder);
});

it('shows the file url in a selectable field with a copy fallback for plain http', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('value="'.e($media->appUrl()).'"')
        ->assertSeeHtml('data-test="media-details-copy"')
        ->assertSeeHtml((string) Js::from(__('hata::media.details.copied')))
        ->assertSeeHtml((string) Js::from(__('hata::media.details.copy_failed')));
});

it('points Download at the app route with the download flag', function () {
    $this->actingAs(mediaEditor());
    $media = Media::factory()->create();

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('href="'.e($media->appUrl().'?download=1').'"');
});
