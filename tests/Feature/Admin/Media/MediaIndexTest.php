<?php

use App\Models\User;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Hata\Support\Media\DirectServing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

function mediaViewer(): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view'])->create();
}

function markMediaUsed(Media $media): void
{
    MediaUsage::query()->create([
        'media_id' => $media->id,
        'usable_type' => User::class,
        'usable_id' => 1,
        'field' => 'cover',
    ]);
}

it('shows the library to users with media.view', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->create(['name' => 'hero-kyiv-autumn.webp']);
    Media::factory()->document()->create(['name' => 'price-list.pdf']);

    $this->get(route('admin.media.index'))
        ->assertOk()
        ->assertSee('hero-kyiv-autumn.webp')
        ->assertSee('price-list.pdf');
});

it('is forbidden without media.view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.media.index'))->assertForbidden();
});

it('shows the content group in the sidebar only with media.view', function () {
    $this->actingAs(mediaViewer());
    $this->get(route('admin.dashboard'))
        ->assertSee(__('hata::navigation.content'))
        ->assertSee(route('admin.media.index'));

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $this->get(route('admin.dashboard'))->assertDontSee(route('admin.media.index'));
});

it('filters by folder, no folder, type, month, unused and search', function (string $filter) {
    $this->actingAs(mediaViewer());
    config(['hata.admin.timezone' => 'Europe/Kyiv']);

    [$params, $visible, $hidden] = match ($filter) {
        'folder' => (function () {
            $folder = MediaFolder::factory()->create();
            Media::factory()->inFolder($folder)->create(['name' => 'in-folder.webp']);
            Media::factory()->create(['name' => 'outside.webp']);

            return [['folder' => (string) $folder->id], 'in-folder.webp', 'outside.webp'];
        })(),
        'no folder' => (function () {
            Media::factory()->inFolder(MediaFolder::factory()->create())->create(['name' => 'in-folder.webp']);
            Media::factory()->create(['name' => 'loose.webp']);

            return [['folder' => 'none'], 'loose.webp', 'in-folder.webp'];
        })(),
        'type' => (function () {
            Media::factory()->document()->create(['name' => 'price-list.pdf']);
            Media::factory()->image()->create(['name' => 'photo.webp']);

            return [['type' => 'document'], 'price-list.pdf', 'photo.webp'];
        })(),
        'month' => (function () {
            // 01:30 on 1 October in Kyiv, 22:30 on 30 September in UTC.
            Media::factory()->create(['name' => 'october.webp', 'created_at' => '2026-09-30 22:30:00']);
            // 00:30 on 1 November in Kyiv, still 31 October in UTC.
            Media::factory()->create(['name' => 'november.webp', 'created_at' => '2026-10-31 22:30:00']);

            return [['month' => '2026-10'], 'october.webp', 'november.webp'];
        })(),
        'unused' => (function () {
            Media::factory()->create(['name' => 'never-placed.webp']);
            markMediaUsed(Media::factory()->create(['name' => 'in-use.webp']));

            return [['unused' => true], 'never-placed.webp', 'in-use.webp'];
        })(),
        'search' => (function () {
            Media::factory()->create(['name' => 'kyiv.webp', 'alt' => ['uk' => 'Осінній Київ']]);
            Media::factory()->create(['name' => 'lviv.webp', 'alt' => ['uk' => 'Львів']]);

            return [['search' => 'КИЇВ'], 'kyiv.webp', 'lviv.webp'];
        })(),
    };

    Livewire::test('hata::admin.media.index')
        ->assertSee($hidden)
        ->set($params)
        ->assertSee($visible)
        ->assertDontSee($hidden);
})->with(['folder', 'no folder', 'type', 'month', 'unused', 'search']);

it('falls back to all files when the open folder no longer exists', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->create(['name' => 'anywhere.webp']);

    Livewire::withQueryParams(['folder' => '999'])
        ->test('hata::admin.media.index')
        ->assertSet('folder', null)
        ->assertSee('anywhere.webp');
});

it('shows 40 tiles and more on demand', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->count(45)->create();

    $component = Livewire::test('hata::admin.media.index');
    expect(substr_count($component->html(), 'data-test="media-tile"'))->toBe(40);

    $component->call('showMore');
    expect(substr_count($component->html(), 'data-test="media-tile"'))->toBe(45);
});

it('refuses a grid limit set from the client', function () {
    $this->actingAs(mediaViewer());

    Livewire::test('hata::admin.media.index')->set('gridLimit', 1_000_000_000);
})->throws(CannotUpdateLockedPropertyException::class);

it('never grows the grid past its cap', function () {
    $this->actingAs(mediaViewer());

    $component = Livewire::test('hata::admin.media.index');

    foreach (range(1, 15) as $ignored) {
        $component->call('showMore');
    }

    $component->assertSet('gridLimit', 400);
});

it('tells the user to switch to the list once the grid reached its cap', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->count(401)->create();

    $component = Livewire::test('hata::admin.media.index')
        ->assertDontSeeHtml('data-test="media-grid-capped"');

    foreach (range(1, 9) as $ignored) {
        $component->call('showMore');
    }

    $component->assertSet('gridLimit', 400)
        ->assertDontSeeHtml('data-test="media-show-more"')
        ->assertSeeHtml('data-test="media-grid-capped"')
        ->assertSee(__('hata::media.index.grid_capped', ['shown' => 400, 'total' => 401]));
});

it('ignores hostile url input', function (array $params) {
    $this->actingAs(mediaViewer());
    Media::factory()->create(['name' => 'still-here.webp']);

    $this->get(route('admin.media.index', $params))
        ->assertOk()
        ->assertSee('still-here.webp');
})->with([
    'sort column' => [['sortBy' => 'id;drop']],
    'sort direction' => [['sortDirection' => 'sideways']],
    'month 13' => [['month' => '2026-13']],
    'month garbage' => [['month' => 'garbage']],
    'folder text' => [['folder' => 'abc']],
    'missing folder' => [['folder' => '999999']],
    'type' => [['type' => 'evil']],
    'view' => [['view' => 'evil']],
]);

it('sorts the list by size', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->create(['name' => 'large.webp', 'size' => 300_000]);
    Media::factory()->create(['name' => 'small.webp', 'size' => 100_000]);
    Media::factory()->create(['name' => 'medium.webp', 'size' => 200_000]);

    Livewire::test('hata::admin.media.index')
        ->set('view', 'list')
        ->call('sort', 'size')
        ->assertSeeInOrder(['small.webp', 'medium.webp', 'large.webp'])
        ->call('sort', 'size')
        ->assertSeeInOrder(['large.webp', 'medium.webp', 'small.webp']);
});

it('keeps filters in the url', function () {
    $this->actingAs(mediaViewer());
    Media::factory()->document()->create(['name' => 'price-list.pdf']);
    Media::factory()->image()->create(['name' => 'photo.webp']);

    Livewire::withQueryParams(['type' => 'document'])
        ->test('hata::admin.media.index')
        ->assertSet('type', 'document')
        ->assertSee('price-list.pdf')
        ->assertDontSee('photo.webp');
});

it('does not run a query per tile', function (string $view) {
    $this->actingAs(mediaViewer());
    $folder = MediaFolder::factory()->create();

    $countQueries = function () use ($view): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::withQueryParams(['view' => $view])->test('hata::admin.media.index');
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    markMediaUsed(Media::factory()->inFolder($folder)->create(['alt' => ['en' => 'Alt']]));
    $countQueries();
    $withOne = $countQueries();

    Media::factory()->count(12)->inFolder($folder)->create();
    Media::factory()->count(12)->document()->create();
    $withMany = $countQueries();

    expect($withMany)->toBe($withOne);
})->with(['grid', 'list']);

it('drags the tile, not its thumbnail image, so the uploader does not take it for a file', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit'])->create());
    Media::factory()->create(['thumb_path' => 'media/thumbs/x.webp']);

    Livewire::test('hata::admin.media.index')
        ->assertSeeHtmlInOrder(['draggable="true"', 'loading="lazy" draggable="false"']);

    Livewire::withQueryParams(['view' => 'list'])->test('hata::admin.media.index')
        ->assertSeeHtml('loading="lazy" draggable="false"');
});

it('marks the open and the selected files in the grid and the list', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit'])->create());
    [$open, $picked, $other] = Media::factory()->count(3)->create(['thumb_path' => 'media/thumbs/x.webp'])->all();

    $grid = Livewire::test('hata::admin.media.index')
        ->call('select', $open->id)
        ->set('selected', [$picked->id])
        ->html();

    $active = fn (string $html, Media $media, string $attribute): bool => (bool) preg_match(
        '/'.preg_quote($attribute.'="'.$media->id.'"', '/').'[^>]*data-active="true"|data-active="true"[^>]*'.preg_quote($attribute.'="'.$media->id.'"', '/').'/',
        $html,
    );

    expect($active($grid, $open, 'data-media-id'))->toBeTrue()
        ->and($active($grid, $picked, 'data-media-id'))->toBeTrue()
        ->and($active($grid, $other, 'data-media-id'))->toBeFalse()
        ->and($grid)->toContain('outline-accent');

    $list = Livewire::withQueryParams(['view' => 'list'])->test('hata::admin.media.index')
        ->call('select', $open->id)
        ->html();

    expect(substr_count($list, 'data-active="true"'))->toBe(1);
});

it('keeps the outline of the open tile when it has focus', function () {
    $this->actingAs(mediaViewer());
    $media = Media::factory()->create();

    $html = Livewire::test('hata::admin.media.index')->call('select', $media->id)->html();

    preg_match('/<button[^>]*data-media-id="'.$media->id.'"[^>]*>/', $html, $tile);

    $classes = preg_match('/class="([^"]*)"/', $tile[0] ?? '', $match) ? explode(' ', $match[1]) : [];

    expect($tile[0] ?? '')->toContain('data-active="true"')
        ->and($classes)->toContain('outline-2', 'outline-accent')
        ->not->toContain('focus:outline-none')
        ->and($classes)->toContain('focus-visible:outline-dashed');
});

it('shows the stable app url in the details panel even for a direct cloud file', function () {
    $this->actingAs(mediaViewer());
    config(['filesystems.disks.s3.driver' => 's3', 'filesystems.disks.s3.url' => 'https://cdn.example.test']);
    Storage::fake('s3', ['url' => 'https://cdn.example.test']);
    $media = Media::factory()->create(['disk' => 's3', 'path' => 'media/2026/11/K-v1.webp']);

    expect($media->url())->toStartWith('https://cdn.example.test/');

    Livewire::test('hata::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('value="'.$media->appUrl().'"')
        ->assertDontSeeHtml('value="'.$media->url().'"');
});

describe('direct serving warning', function () {
    beforeEach(function () {
        config(['hata.media.disk' => 'public']);
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'settings.edit'])->create());
    });

    it('warns settings editors when the storage link is missing or the files are unreachable', function (string $state) {
        DirectServing::fake(state: $state);

        $this->get(route('admin.media.index'))
            ->assertOk()
            ->assertSee(__('hata::media.index.direct_warning'))
            ->assertSee(route('admin.settings.edit', ['tab' => 'media']), false);
    })->with(['link_missing', 'unreachable']);

    it('stays quiet while direct serving works or has not been checked', function (string $state) {
        DirectServing::fake(state: $state);

        $this->get(route('admin.media.index'))->assertOk()->assertDontSee(__('hata::media.index.direct_warning'));
    })->with(['direct', 'unchecked']);

    it('stays quiet without settings.edit', function () {
        $this->actingAs(mediaViewer());
        DirectServing::fake(state: 'link_missing');

        $this->get(route('admin.media.index'))->assertOk()->assertDontSee(__('hata::media.index.direct_warning'));
    });

    it('stays quiet when the site disk is not served by this server', function () {
        config(['hata.media.disk' => 's3']);
        DirectServing::fake(state: 'link_missing');

        $this->get(route('admin.media.index'))->assertOk()->assertDontSee(__('hata::media.index.direct_warning'));
    });
});
