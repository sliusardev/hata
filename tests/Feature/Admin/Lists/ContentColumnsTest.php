<?php

use App\Models\User;
use Hata\Actions\Content\TrashEntry;
use Hata\Admin;
use Hata\Models\Entry;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
use Hata\Testing\AssertsListQueries;
use Livewire\Livewire;

uses(AssertsListQueries::class);

beforeEach(function () {
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType)->register(new TagsType);
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('admin')->create(['name' => 'Chief']));
});

/**
 * The text of the column headers, in order.
 *
 * @return list<string>
 */
function contentColumnHeaders(string $html): array
{
    preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $html, $matches);

    return array_values(array_filter(array_map(fn (string $header): string => trim(preg_replace('/\s+/', ' ', strip_tags($header))), $matches[1])));
}

function contentList(string $type, string $tab = 'all')
{
    if ($tab !== 'trash' && ! Entry::query()->where('type', $type)->exists()) {
        Entry::factory()->published()->create(['type' => $type, 'title' => 'Seed '.$type, 'slug' => 'seed-'.$type, 'path' => 'seed-'.$type]);
    }

    return Livewire::test('hata::admin.content.index', ['type' => $type])->set('tab', $tab);
}

function viewsColumn(array $overrides = []): TableColumn
{
    return new TableColumn(...[
        'key' => 'views',
        'label' => 'Views',
        'value' => fn (Entry $entry): string => 'v'.$entry->id,
        'sort' => fn ($query, string $direction) => $query->orderBy('id', $direction),
        ...$overrides,
    ]);
}

test('the content list renders Hata columns from the registry', function () {
    Entry::factory()->published()->create(['title' => 'About']);

    expect(contentColumnHeaders(contentList('pages')->html()))->toBe([
        __('hata::content.list.columns.title'),
        __('hata::content.list.columns.status'),
        __('hata::content.list.columns.author'),
        __('hata::content.list.columns.date'),
        __('hata::content.list.columns.actions'),
    ]);

    $categories = contentColumnHeaders(contentList('categories')->html());
    expect($categories)->toContain(__('hata::content.list.columns.records'))
        ->not->toContain(__('hata::content.list.columns.status'))
        ->not->toContain(__('hata::content.list.columns.author'));

    $posts = contentColumnHeaders(contentList('posts')->html());
    expect($posts[1])->toBe('Categories / Tags');
});

test('the trash shows the removal column instead of status and date', function () {
    $entry = Entry::factory()->published()->create(['title' => 'Gone']);
    app(TrashEntry::class)->handle(auth()->user(), $entry);

    $html = contentList('pages', 'trash')->html();

    expect(contentColumnHeaders($html))->toBe([
        __('hata::content.list.columns.title'),
        __('hata::content.list.columns.author'),
        __('hata::content.list.columns.removal'),
        __('hata::content.list.columns.actions'),
    ]);
});

test('a site column on content.* shows on every type and on content.posts only on posts', function () {
    Admin::columns('content.*')->add(viewsColumn());
    Admin::columns('content.posts')->add(viewsColumn(['key' => 'reads', 'label' => 'Reads']));

    expect(contentList('pages')->html())->toContain('Views')->not->toContain('Reads')
        ->and(contentList('categories')->html())->toContain('Views')
        ->and(contentList('posts')->html())->toContain('Views')->toContain('Reads');
});

test('content.posts can remove the author column for posts only', function () {
    Admin::columns('content.posts')->remove('author');

    expect(contentList('posts')->html())->not->toContain(__('hata::content.list.columns.author'))
        ->and(contentList('pages')->html())->toContain(__('hata::content.list.columns.author'));
});

test('sorting by a registered column flattens the tree', function () {
    Admin::columns('content.*')->add(viewsColumn());
    $parent = Entry::factory()->published()->create(['title' => 'Parent', 'slug' => 'parent', 'path' => 'parent']);
    Entry::factory()->published()->create(['title' => 'Child', 'slug' => 'child', 'path' => 'parent/child', 'parent_id' => $parent->id]);

    $component = contentList('pages');
    expect($component->html())->toContain('data-depth="1"');

    $component->call('sort', 'views');
    expect($component->get('sort'))->toBe('views')
        ->and($component->html())->not->toContain('data-depth="1"');

    $component->call('resetSort');
    expect($component->html())->toContain('data-depth="1"');
});

test('a column that is not visible cannot be sorted by', function () {
    Admin::columns('content.*')->add(viewsColumn(['permission' => 'nobody.can']));

    $component = contentList('pages')->call('sort', 'views');

    expect($component->get('sort'))->toBe('');
});

test('the content list does not grow in queries with rows', function () {
    Admin::columns('content.*')->add(viewsColumn([
        'value' => fn (Entry $entry): string => (string) $entry->author?->name,
        'with' => ['author'],
    ]));

    $this->assertListQueriesStable(
        fn (int $count) => Entry::factory()->count($count)->published()->create(['author_id' => auth()->id()]),
        fn () => contentList('pages'),
    );
});
