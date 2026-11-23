<?php

use App\Models\User;
use Hata\Actions\Media\MoveMedia;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', 'media.upload', 'media.delete'])->create());
});

/**
 * Call an action and return the queries it ran.
 *
 * @return list<string>
 */
function queriesOf(Testable $component, string $method, mixed ...$params): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $component->call($method, ...$params);
    DB::disableQueryLog();

    return array_column(DB::getQueryLog(), 'query');
}

/**
 * The names of the islands a response re-rendered.
 *
 * @return list<string>
 */
function renderedIslands(Testable $component): array
{
    return collect($component->effects['islandFragments'] ?? [])
        ->map(fn (string $fragment): string => str($fragment)->match('/FRAGMENT:type=island\|name=([a-z]+)\|/')->value())
        ->unique()
        ->sort()
        ->values()
        ->all();
}

it('renders only the files and the details panel when a tile is opened', function () {
    $folder = MediaFolder::factory()->create();
    $media = Media::factory()->inFolder($folder)->create(['name' => 'opened.webp']);

    $component = Livewire::test('hata::admin.media.index');
    $queries = queriesOf($component, 'select', $media->id, true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['details', 'files'])
        ->and(implode("\n", $component->effects['islandFragments']))->toContain('data-test="media-details-actions"')
        ->and($component->get('selectedId'))->toBe($media->id);

    // The grid and the panel only: no file counts per folder, no library totals, no month range.
    expect(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'group by') || str_contains($query, 'min(')))->toBeEmpty()
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'is null')))->toBeEmpty()
        ->and(count($queries))->toBeLessThanOrEqual(7);

    // Closing it again renders the same two islands.
    $component->call('closeDetails', true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['details', 'files'])
        ->and(implode("\n", $component->effects['islandFragments']))->not->toContain('data-test="media-details-actions"');
});

it('renders nothing when the open tile is clicked again', function () {
    $media = Media::factory()->create();

    $component = Livewire::test('hata::admin.media.index')->call('select', $media->id)->call('select', $media->id, true);

    expect($component->effects)->not->toHaveKey('html')
        ->and($component->effects)->not->toHaveKey('islandFragments');
});

it('renders only the files and the bulk bar when tiles are picked', function (string $method, array $params) {
    [$one, $two] = Media::factory()->count(2)->create();
    $params = array_map(fn (mixed $param): mixed => $param === 'ids' ? [$one->id, $two->id] : ($param === 'id' ? $one->id : $param), $params);

    $component = Livewire::test('hata::admin.media.index')->call($method, ...$params);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['bulk', 'files'])
        ->and(implode("\n", $component->effects['islandFragments']))->toContain('data-test="media-bulk-bar"', 'data-test="media-tile-picked"');
})->with([
    'a plain click while selecting' => ['toggleSelected', ['id', true]],
    'a Ctrl click' => ['pick', ['id', 'toggle', true]],
    'the mouse box' => ['selectBox', ['ids', false, true]],
]);

it('renders only the files for "Show more"', function () {
    Media::factory()->count(45)->create();

    $component = Livewire::test('hata::admin.media.index')->call('showMore', true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['files'])
        ->and(substr_count($component->effects['islandFragments'][0], 'data-test="media-tile"'))->toBe(45);
});

it('narrows the list view too: opening a row, a checkbox and a sort on the first page', function () {
    Media::factory()->count(30)->create();
    $media = Media::query()->latest('id')->firstOrFail();

    $component = Livewire::test('hata::admin.media.index')->set('view', 'list')->call('select', $media->id, true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['details', 'files']);

    $component->call('toggleSelected', $media->id, true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['bulk', 'files'])
        ->and(implode("\n", $component->effects['islandFragments']))->toContain('wire:key="media-pick-'.$media->id.'-on"');

    $component->call('sort', 'name', true)->assertSet('sortBy', 'name');

    expect($component->effects)->not->toHaveKey('html')
        ->and(renderedIslands($component))->toBe(['files']);

    // From page 2 a sort goes back to page 1, and the page links are outside the islands.
    $component->call('gotoPage', 2)->call('sort', 'size', true)->assertSet('sortBy', 'size');

    expect($component->effects)->toHaveKey('html');
});

it('counts no files when a narrowed action renders an empty folder', function () {
    $folder = MediaFolder::factory()->create();
    MediaFolder::factory()->create(['parent_id' => $folder->id]);
    Media::factory()->create();

    $component = Livewire::withQueryParams(['folder' => (string) $folder->id])->test('hata::admin.media.index');
    $queries = queriesOf($component, 'selectBox', [], false, true);

    expect($component->effects)->not->toHaveKey('html')
        ->and(implode("\n", $component->effects['islandFragments']))->toContain('data-test="media-no-files"')
        ->and(collect($queries)->filter(fn (string $query): bool => str_contains($query, 'group by')))->toBeEmpty();
});

it('uses no wire: actions inside the islands, so Livewire never scopes a request to one', function () {
    [$media, $other] = Media::factory()->count(2)->create(['original_path' => 'media/originals/o.jpg']);
    $plain = Media::factory()->document()->create();
    $page = fn () => Livewire::test('hata::admin.media.index');

    $islands = implode("\n", [
        ...$page()->call('toggleSelected', $media->id)->call('toggleSelected', $other->id, true)->effects['islandFragments'],
        ...$page()->call('select', $media->id, true)->effects['islandFragments'],
        ...$page()->call('select', $plain->id, true)->effects['islandFragments'],
        ...$page()->set('view', 'list')->call('toggleSelected', $media->id, true)->effects['islandFragments'],
        ...$page()->set('search', 'matches-nothing')->call('selectBox', [], false, true)->effects['islandFragments'],
    ]);

    // Anything but these marks an action as coming from the island (wire:model without a modifier sends nothing by itself).
    preg_match_all('/\swire:[a-z.:-]+/', $islands, $found);
    $directives = collect($found[0])->map(fn (string $name): string => trim($name))->unique()->values();

    expect($islands)->toContain('data-test="media-bulk-bar"', 'data-test="media-details-actions"', 'data-test="media-tile"', 'data-test="media-select-page"')
        ->and($directives->diff(['wire:key', 'wire:model', 'wire:navigate', 'wire:ignore'])->all())->toBe([]);
});

it('renders the whole page when the same actions are called without the flag', function () {
    $media = Media::factory()->create(['name' => 'plain.webp']);

    $component = Livewire::test('hata::admin.media.index')->call('select', $media->id);

    expect($component->effects)->toHaveKey('html')
        ->and($component->effects)->not->toHaveKey('islandFragments');

    $component->assertSeeHtml('data-test="media-details-actions"')
        ->call('toggleSelected', $media->id)
        ->assertSeeHtml('data-test="media-bulk-bar"');
});

it('renders the whole page when opening a file has to ask about unsaved changes', function () {
    [$open, $other] = Media::factory()->count(2)->create();

    $component = Livewire::test('hata::admin.media.index')
        ->call('select', $open->id)
        ->set('details.title', 'Unsaved')
        ->call('select', $other->id, true);

    expect($component->effects)->toHaveKey('html');
    $component->assertSet('showDiscardModal', true)->assertSet('selectedId', $open->id);
});

it('renders the whole page when a narrowed action comes with a property update', function () {
    $media = Media::factory()->create(['name' => 'kept.webp']);
    Media::factory()->create(['name' => 'filtered-out.webp']);

    // A search typed just before the click: it changes the file count and tiles outside the islands.
    $component = Livewire::test('hata::admin.media.index');
    $component->update(
        calls: [['method' => 'select', 'params' => [$media->id, true], 'path' => '']],
        updates: ['search' => 'kept'],
    );

    expect($component->effects)->toHaveKey('html')
        ->and($component->effects)->not->toHaveKey('islandFragments');
    $component->assertSet('selectedId', $media->id)->assertDontSee('filtered-out.webp');
});

it('renders the whole page when a narrowed action shares a request with another one', function () {
    $media = Media::factory()->create();

    // The delete dialog lives outside the islands, so the second call must reach it.
    $component = Livewire::test('hata::admin.media.index');
    $component->update(calls: [
        ['method' => 'toggleSelected', 'params' => [$media->id, true], 'path' => ''],
        ['method' => 'confirmBulkDelete', 'params' => [], 'path' => ''],
    ]);

    expect($component->effects)->toHaveKey('html')
        ->and($component->effects)->not->toHaveKey('islandFragments');
    $component->assertSet('showDeleteModal', true);
});

it('renders the whole page for an action Livewire marks as coming from an island', function () {
    $media = Media::factory()->create();
    $island = ['island' => ['name' => 'details', 'mode' => 'morph']];

    // Nothing inside the islands sends this mark; if something ever does, Livewire must not render the island alone.
    $component = Livewire::test('hata::admin.media.index')->call('select', $media->id);
    $component->update(calls: [['method' => 'confirmDelete', 'params' => [$media->id], 'path' => '', 'metadata' => $island]]);

    expect($component->effects)->toHaveKey('html')
        ->and($component->effects)->not->toHaveKey('islandFragments');
    $component->assertSet('showDeleteModal', true);
});

it('still renders the page for an action that shares a request with an upload step', function () {
    $media = Media::factory()->create();
    $file = UploadedFile::fake()->createWithContent('queued.jpg', makeImage('jpg', 300, 200));

    // A tile click held back while the queue's request was in flight goes out together with the next one.
    $component = Livewire::test('hata::admin.media.index')->set('upload', $file);
    $component->update(calls: [
        ['method' => 'storeUpload', 'params' => [true], 'path' => ''],
        ['method' => 'select', 'params' => [$media->id, true], 'path' => ''],
    ]);

    expect($component->effects)->toHaveKey('html');
    $component->assertSet('selectedId', $media->id)->assertSeeHtml('data-test="media-details-actions"');
});

it('stores a queued upload without rendering the page, and renders it when asked to', function () {
    $file = fn (string $name): UploadedFile => UploadedFile::fake()->createWithContent($name, makeImage('jpg', 300, 200));

    $deferred = Livewire::test('hata::admin.media.index')->set('upload', $file('first.jpg'))->call('storeUpload', true);

    expect($deferred->effects)->not->toHaveKey('html')
        ->and($deferred->effects['returns'][0]['ok'])->toBeTrue()
        ->and(Media::query()->where('name', 'first.webp')->exists())->toBeTrue();

    // The refresh the queue sends afterwards shows the file.
    $deferred->call('$refresh')->assertSee('first.webp');

    Livewire::test('hata::admin.media.index')->set('upload', $file('second.jpg'))->call('storeUpload')->assertSee('second.webp');
});

it('moves a selection into a folder with one update', function () {
    $folder = MediaFolder::factory()->create();
    $files = Media::factory()->count(5)->create();

    DB::enableQueryLog();
    app(MoveMedia::class)->handle(auth()->user(), $files, $folder);
    $updates = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update'));
    DB::disableQueryLog();

    expect($updates)->toHaveCount(1)
        ->and(Media::query()->where('folder_id', $folder->id)->count())->toBe(5)
        ->and($files->every(fn (Media $media): bool => $media->folder_id === $folder->id && $media->isClean()))->toBeTrue();
});

it('counts the files of every folder with one query', function () {
    [$full, $empty] = MediaFolder::factory()->count(2)->create();
    Media::factory()->count(3)->inFolder($full)->create();
    Media::factory()->create();

    DB::enableQueryLog();
    $tree = MediaFolder::tree()->keyBy('id');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2)
        ->and($tree[$full->id]->media_count)->toBe(3)
        ->and($tree[$empty->id]->media_count)->toBe(0)
        ->and($tree[$full->id]->isClean())->toBeTrue();
});
