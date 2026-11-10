<?php

use App\Actions\Media\ZipMedia;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Js;
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

it('moves the selected files with "Move to…" and clears the selection', function () {
    $this->actingAs(mediaManager(['media.edit']));
    $folder = MediaFolder::factory()->create(['name' => 'Products']);
    [$first, $second] = Media::factory()->count(2)->create();
    $untouched = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$first->id, $second->id])
        ->assertSeeHtml('data-test="media-bulk-move"')
        ->assertSee('Products')
        ->call('moveSelected', $folder->id)
        ->assertSet('selected', [])
        ->assertDispatched('toast-show');

    expect($first->refresh()->folder_id)->toBe($folder->id)
        ->and($second->refresh()->folder_id)->toBe($folder->id)
        ->and($untouched->refresh()->folder_id)->toBeNull();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$first->id])
        ->call('moveSelected', null);

    expect($first->refresh()->folder_id)->toBeNull();
});

it('hides "Move to…" without media.edit', function () {
    $this->actingAs(mediaManager());
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$media->id])
        ->assertSeeHtml('data-test="media-bulk-bar"')
        ->assertDontSeeHtml('data-test="media-bulk-move"');
});

it('lets the browser pick the dragged ids, with the "N files" forms rendered once', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$first, $second, $other] = Media::factory()->count(3)->create();
    $dragging = fn (int $id): string => 'mediaDragStart($event, '.$id.', $wire)';
    $labels = e(json_encode([
        'locale' => app()->getLocale(),
        'forms' => collect([1, 2, 5])->mapWithKeys(fn (int $count) => [$count => trans_choice('media.bulk.dragging', $count, ['count' => ':count'])])->all(),
    ]));

    foreach (['grid', 'list'] as $view) {
        $html = Livewire::withQueryParams(['view' => $view])->test('pages::admin.media.index')
            ->set('selected', [$first->id, $second->id])
            ->html();

        expect(html_entity_decode($html, ENT_QUOTES))->toContain($dragging($first->id))
            ->toContain($dragging($second->id))
            ->toContain($dragging($other->id))
            ->not->toContain(Js::from([$first->id, $second->id])->toHtml())
            ->and(substr_count($html, 'data-media-drag-labels='))->toBe(1)
            ->and($html)->toContain('data-media-drag-labels="'.$labels.'"');
    }
});

it('selects a range with shift and single files with ctrl or cmd', function () {
    $this->actingAs(mediaManager(['media.edit']));
    // Newest first: $files[0] is shown first.
    $files = collect(range(1, 6))
        ->map(fn (int $day) => Media::factory()->create(['created_at' => now()->subDays($day)]))
        ->values();
    [$a, $b, $c, $d, $e, $f] = $files->all();

    Livewire::test('pages::admin.media.index')
        ->call('pick', $b->id, 'toggle')
        ->assertSet('selected', [$b->id])
        ->call('pick', $e->id, 'range')
        ->assertSet('selected', [$b->id, $c->id, $d->id, $e->id])
        ->call('pick', $c->id, 'toggle')
        ->assertSet('selected', [$b->id, $d->id, $e->id])
        ->call('pick', $a->id, 'range')
        ->assertSet('selected', [$b->id, $d->id, $e->id, $a->id, $c->id]);
});

it('starts a shift range from the file opened last', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$a, $b, $c] = collect(range(1, 3))
        ->map(fn (int $day) => Media::factory()->create(['created_at' => now()->subDays($day)]))
        ->all();

    Livewire::test('pages::admin.media.index')
        ->call('select', $c->id)
        ->call('pick', $a->id, 'range')
        ->assertSet('selected', [$a->id, $b->id, $c->id]);
});

it('anchors the shift range at a checkbox ticked in the list', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$a, $b, $c] = collect(range(1, 3))
        ->map(fn (int $day) => Media::factory()->create(['created_at' => now()->subDays($day)]))
        ->all();

    Livewire::withQueryParams(['view' => 'list'])->test('pages::admin.media.index')
        ->set('selected', [(string) $a->id])
        ->assertSet('selectionAnchor', $a->id)
        ->call('pick', $c->id, 'range')
        ->assertSet('selected', [$a->id, $b->id, $c->id]);
});

it('selects the files touched by the mouse box, replacing or adding with a modifier', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$a, $b, $c, $d] = Media::factory()->count(4)->create()->all();

    Livewire::test('pages::admin.media.index')
        ->assertSeeHtml('x-data="mediaMarquee"')
        ->assertSeeHtml('data-media-id="'.$a->id.'"')
        ->set('selected', [$d->id])
        ->call('selectBox', [$a->id, (string) $b->id, 'junk', 999999])
        ->assertSet('selected', [$a->id, $b->id])
        ->call('selectBox', [$c->id], true)
        ->assertSet('selected', [$a->id, $b->id, $c->id])
        ->assertSet('selectionAnchor', $c->id)
        ->call('selectBox', [], false)
        ->assertSet('selected', []);
});

it('forgets the shift anchor when the selection is cleared', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$a, $b] = Media::factory()->count(2)->create()->all();

    Livewire::test('pages::admin.media.index')
        ->call('pick', $a->id, 'toggle')
        ->assertSet('selectionAnchor', $a->id)
        ->call('clearSelection')
        ->assertSet('selected', [])
        ->assertSet('selectionAnchor', null)
        ->call('pick', $b->id, 'range')
        ->assertSet('selected', [$b->id]);
});

it('toggles the file for a pick mode it does not know', function () {
    $this->actingAs(mediaManager(['media.edit']));
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->call('pick', $media->id, 'whatever')
        ->assertSet('selected', [$media->id])
        ->call('pick', $media->id, 'whatever')
        ->assertSet('selected', []);
});

it('keeps the shift anchor when several checkboxes change at once', function () {
    $this->actingAs(mediaManager(['media.edit']));
    [$a, $b, $c] = collect(range(1, 3))
        ->map(fn (int $day) => Media::factory()->create(['created_at' => now()->subDays($day)]))
        ->all();

    Livewire::withQueryParams(['view' => 'list'])->test('pages::admin.media.index')
        ->set('selected', [(string) $b->id])
        ->assertSet('selectionAnchor', $b->id)
        ->set('selected', [(string) $a->id, (string) $b->id, (string) $c->id])
        ->assertSet('selectionAnchor', $b->id)
        ->set('selected', [])
        ->assertSet('selectionAnchor', $b->id);
});

it('moves nothing from the bulk bar without media.edit', function () {
    $this->actingAs(mediaManager());
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->create();

    Livewire::test('pages::admin.media.index')
        ->set('selected', [$media->id])
        ->call('moveSelected', $folder->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && ($params['slots']['text'] ?? null) === __('media.errors.forbidden'))
        ->assertSet('selected', [$media->id]);

    expect($media->refresh()->folder_id)->toBeNull();
});

it('lets a plain click unpick tiles once anything is selected', function () {
    $this->actingAs(mediaManager());
    [$one, $two] = Media::factory()->count(2)->create();

    Livewire::test('pages::admin.media.index')
        ->assertSeeHtml('$wire.select('.$one->id.')')
        ->call('selectBox', [$one->id, $two->id])
        ->assertSeeHtml('$wire.toggleSelected('.$one->id.')')
        ->assertDontSeeHtml('$wire.select('.$one->id.')');
});
