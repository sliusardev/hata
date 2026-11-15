<?php

use App\Actions\Content\TrashEntry;
use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Models\Media;
use App\Models\User;
use App\Support\Content\ContentTypes;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [
            ['code' => 'en', 'active' => true],
            ['code' => 'uk', 'active' => true],
            ['code' => 'pl', 'active' => false],
        ],
        'content_default_locale' => 'en',
    ]);
});

/**
 * @param  list<string>  $permissions
 */
function contentViewer(array $permissions = []): User
{
    return User::factory()->withPermissions(['admin.access', 'pages.view', ...$permissions])->create(['name' => 'Viewer']);
}

/**
 * @param  array<string, string>  $query
 */
function entryList(array $query = []): Testable
{
    return Livewire::withQueryParams($query)->test('pages::admin.content.index', ['type' => 'pages']);
}

test('guests are redirected to the login page', function () {
    $this->get(route('admin.content.index', 'pages'))->assertRedirect(route('login'));
});

test('it returns 403 without any of pages.view, pages.edit and pages.create', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.content.index', 'pages'))->assertForbidden();
});

test('an unknown type is 404', function () {
    $this->actingAs(contentViewer());

    $this->get('/admin/content/unknown')->assertNotFound();
});

test('the list opens with pages.view', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'About the company']);

    $this->get(route('admin.content.index', 'pages'))->assertOk()->assertSee('About the company');
});

test('the sidebar shows the pages item only with pages.view', function () {
    $this->actingAs(contentViewer());
    $this->get(route('admin.dashboard'))
        ->assertSee(route('admin.content.index', 'pages'))
        ->assertSee(__('navigation.content'));

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $this->get(route('admin.dashboard'))
        ->assertDontSee(route('admin.content.index', 'pages'))
        ->assertDontSee(__('navigation.content'));
});

test('edit or create without view opens the list, the sidebar item and a record', function (string $permission) {
    $this->actingAs(User::factory()->withPermissions(['admin.access', $permission])->create());
    $page = Entry::factory()->create(['title' => 'About the company']);

    $this->get(route('admin.dashboard'))->assertSee(route('admin.content.index', 'pages'));
    $this->get(route('admin.content.index', 'pages'))->assertOk()->assertSee('About the company');
    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))->assertOk();
})->with(['pages.edit', 'pages.create']);

test('a translation form opens only from a source the actor may view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.create'])->create());
    $page = Entry::factory()->create();

    $this->get(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'uk']))->assertOk();

    // A record the actor may not view (a rule a later part may add): the translation form refuses it.
    Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'view' && ($arguments[0] ?? null) instanceof Entry ? false : null);

    $this->get(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'uk']))->assertForbidden();
});

test('the content group shows with media.view alone, without the pages item', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $this->get(route('admin.dashboard'))
        ->assertSee(__('navigation.content'))
        ->assertSee(route('admin.media.index'))
        ->assertDontSee(route('admin.content.index', 'pages'));
});

test('tabs filter the records and count them', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->published()->count(2)->sequence(['title' => 'Live one'], ['title' => 'Live two'])->create();
    Entry::factory()->draft()->create(['title' => 'Draft one']);
    Entry::factory()->create(['title' => 'Dateless one', 'status' => EntryStatus::Published, 'published_at' => null]);
    Entry::factory()->scheduled()->create(['title' => 'Future one']);
    Entry::factory()->create(['title' => 'Binned one'])->delete();
    Entry::factory()->inLocale('uk')->published()->create(['title' => 'Other language']);

    $list = entryList(['locale' => 'en']);

    expect($list->instance()->counts)->toBe(['all' => 5, 'published' => 2, 'drafts' => 2, 'scheduled' => 1, 'trash' => 1]);

    $list->assertSee(['Live one', 'Draft one', 'Dateless one', 'Future one'])->assertDontSee(['Binned one', 'Other language']);

    $list->set('tab', 'published')->assertSee(['Live one', 'Live two'])->assertDontSee(['Draft one', 'Future one']);
    $list->set('tab', 'drafts')->assertSee(['Draft one', 'Dateless one'])->assertDontSee(['Live one', 'Future one']);
    $list->set('tab', 'scheduled')->assertSee('Future one')->assertDontSee(['Live one', 'Draft one']);
    $list->set('tab', 'trash')->assertSee('Binned one')->assertDontSee(['Live one', 'Draft one']);
});

test('an unknown tab falls back to all', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'Visible page']);

    entryList(['tab' => 'nonsense'])->assertSee('Visible page');
});

test('search finds Cyrillic titles whatever their case', function (string $term) {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'Про компанію']);
    Entry::factory()->create(['title' => 'Контакти']);

    entryList()->set('search', $term)->assertSee('Про компанію')->assertDontSee('Контакти');
})->with(['компанію', 'КОМПАНІЮ', 'пРо КоМ']);

test('a hierarchical type is listed in tree order and indented by depth', function () {
    $this->actingAs(contentViewer());
    $about = Entry::factory()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    Entry::factory()->create(['title' => 'About us sibling', 'slug' => 'about-us', 'path' => 'about-us']);
    $team = Entry::factory()->childOf($about)->create(['title' => 'Team', 'slug' => 'team', 'path' => 'about/team']);
    $lead = Entry::factory()->childOf($team)->create(['title' => 'Lead', 'slug' => 'lead', 'path' => 'about/team/lead']);
    Entry::factory()->create(['title' => 'Contacts', 'slug' => 'contacts', 'path' => 'contacts']);

    entryList()
        ->assertSet('sort', '')
        ->assertSeeInOrder(['About', 'Team', 'Lead', 'About us sibling', 'Contacts'])
        ->assertSeeHtml('data-depth="0" data-test="entry-row-'.$about->id.'"')
        ->assertSeeHtml('data-depth="1" data-test="entry-row-'.$team->id.'"')
        ->assertSeeHtml('data-depth="2" data-test="entry-row-'.$lead->id.'"');
});

test('rows are indented only on the all tab without a search', function () {
    $this->actingAs(contentViewer());
    $about = Entry::factory()->published()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    $team = Entry::factory()->childOf($about)->published()->create(['title' => 'Team', 'slug' => 'team', 'path' => 'about/team']);
    $about->update(['status' => EntryStatus::Draft]);

    entryList()->assertSeeHtml('data-depth="1" data-test="entry-row-'.$team->id.'"');
    entryList(['tab' => 'published'])->assertSeeHtml('data-depth="0" data-test="entry-row-'.$team->id.'"');
});

test('sorting by title and back to the tree order', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'Zebra', 'slug' => 'a-first', 'path' => 'a-first']);
    Entry::factory()->create(['title' => 'Apple', 'slug' => 'z-last', 'path' => 'z-last']);

    entryList()
        ->assertSeeInOrder(['Zebra', 'Apple'])
        ->call('sort', 'title')
        ->assertSet('sort', 'title')
        ->assertSeeInOrder(['Apple', 'Zebra'])
        ->call('sort', 'title')
        ->assertSeeInOrder(['Zebra', 'Apple'])
        ->call('sort', 'title')
        ->assertSet('sort', '')
        ->call('sort', 'password')
        ->assertSet('sort', '');
});

test('the list shows every record of every language by default, each marked by its language', function () {
    $this->actingAs(contentViewer());
    $about = Entry::factory()->published()->create(['title' => 'About us']);
    $pro = Entry::factory()->translationOf($about, 'uk')->create(['title' => 'Про нас']);
    $foreign = Entry::factory()->inLocale('fr')->create(['title' => 'Page hors du site']);
    $row = fn (Entry $entry): string => 'data-test="entry-row-'.$entry->id.'"';

    $list = entryList()
        ->assertSet('locale', '')
        ->assertSeeHtml([$row($about), $row($pro)])
        ->assertDontSeeHtml($row($foreign))
        ->assertSeeHtml(['data-test="entry-language-'.$about->id.'"', 'data-test="entry-language-'.$pro->id.'"']);

    expect($list->instance()->counts)->toMatchArray(['all' => 2, 'published' => 1, 'drafts' => 1]);

    entryList(['locale' => 'uk'])->assertSeeHtml($row($pro))->assertDontSeeHtml([$row($about), 'data-test="entry-language-'.$pro->id.'"']);
});

test('a chosen language lists its records and is remembered in the session', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'English page']);
    Entry::factory()->inLocale('uk')->create(['title' => 'Українська сторінка']);

    entryList()->set('locale', 'uk')->assertSee('Українська сторінка')->assertDontSee('English page');
    expect(session('content.list_locale'))->toBe('uk');

    entryList()->assertSet('locale', 'uk')->assertSee('Українська сторінка')
        ->set('locale', '')->assertSee('English page');

    entryList()->assertSet('locale', '');
});

test('the trash lists every record on its own, marked by its language', function () {
    $this->actingAs(contentViewer());
    $about = Entry::factory()->create(['title' => 'About us', 'deleted_at' => now()]);
    $pro = Entry::factory()->translationOf($about, 'uk')->create(['title' => 'Про нас', 'deleted_at' => now()]);

    $list = entryList(['tab' => 'trash'])
        ->assertSee(['About us', 'Про нас'])
        ->assertSeeHtml('data-test="entry-language-'.$pro->id.'"');

    expect($list->instance()->counts['trash'])->toBe(2);
});

test('with every language listed the trees follow the site\'s order of languages', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->inLocale('uk')->create(['title' => 'Абетка', 'slug' => 'a', 'path' => 'a']);
    Entry::factory()->create(['title' => 'Zebra', 'slug' => 'z', 'path' => 'z']);
    Entry::factory()->create(['title' => 'Middle', 'slug' => 'm', 'path' => 'm']);

    entryList()->assertSeeInOrder(['Middle', 'Zebra', 'Абетка']);
});

test('a locale that is not on the site shows every language', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'English page']);

    entryList(['locale' => 'de'])->assertSet('locale', '')->assertSee('English page');
    entryList()->set('locale', 'xx')->assertSet('locale', '');
});

test('a row lists every language of the site as a link to the translation or to creating it', function () {
    $this->actingAs(contentViewer(['pages.create']));
    $page = Entry::factory()->published()->create(['title' => 'Group page']);
    $uk = Entry::factory()->translationOf($page, 'uk')->create();

    entryList(['locale' => 'en'])
        ->assertSeeHtml('data-test="translations-'.$page->id.'"')
        ->assertSeeHtml('data-test="chip-en" data-state="this"')
        ->assertSeeHtml('data-test="chip-uk" data-state="draft"')
        ->assertSeeHtml('data-test="chip-pl" data-state="missing"')
        ->assertSee(route('admin.content.edit', ['type' => 'pages', 'entry' => $uk->id]), false)
        ->assertSee(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'pl']));
});

test('a missing translation is not a create link without pages.create', function () {
    $this->actingAs(contentViewer());
    $page = Entry::factory()->create();

    entryList()->assertDontSee(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'uk']));
});

test('one language site hides the language controls and the translations column', function () {
    app(SiteSettings::class)->put(['content_locales' => [['code' => 'en', 'active' => true]], 'content_default_locale' => 'en']);
    $this->actingAs(contentViewer());
    $page = Entry::factory()->create();

    entryList()
        ->assertDontSeeHtml('data-test="list-locale"')
        ->assertDontSeeHtml('data-test="translations-'.$page->id.'"')
        ->assertDontSee(__('content.list.columns.translations'));
});

test('two language site shows the language controls', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create();

    entryList()->assertSeeHtml('data-test="list-locale"');
});

test('the create button needs pages.create, and the new form opens in the language of the list', function () {
    $create = 'href="'.route('admin.content.create', ['type' => 'pages']).'"';

    $this->actingAs(contentViewer());
    entryList()->assertDontSeeHtml($create);

    $this->actingAs(contentViewer(['pages.create']));
    entryList()->set('locale', 'uk')->assertSeeHtml($create);

    Livewire::test('pages::admin.content.create', ['type' => 'pages'])->assertSet('locale', 'uk');
});

test('trashing from the row needs pages.delete and takes the subpages along', function () {
    $parent = Entry::factory()->create(['title' => 'Parent', 'slug' => 'parent', 'path' => 'parent']);
    $child = Entry::factory()->childOf($parent)->create(['slug' => 'child', 'path' => 'parent/child']);

    $this->actingAs(contentViewer());
    entryList()->call('confirmTrash', $parent->id)->assertForbidden();

    $this->actingAs(contentViewer(['pages.delete']));
    entryList()
        ->call('confirmTrash', $parent->id)
        ->assertSet('showTrashModal', true)
        ->assertSee(trans_choice('content.list.trash.confirm_children', 1, ['title' => 'Parent']))
        ->call('trash')
        ->assertSet('showTrashModal', false);

    expect(Entry::withTrashed()->find($parent->id)->trashed())->toBeTrue()
        ->and(Entry::withTrashed()->find($child->id)->trashed())->toBeTrue();
});

test('restoring from the trash tab needs pages.edit', function () {
    $entry = Entry::factory()->create(['title' => 'Binned page']);
    app(TrashEntry::class)->handle(User::factory()->withRole('editor')->create(), $entry);

    $this->actingAs(contentViewer(['pages.delete']));
    entryList(['tab' => 'trash'])->call('restore', $entry->id)->assertForbidden();

    $removal = Entry::withTrashed()->find($entry->id)->deleted_at->addDays(30)->tz(config('admin.timezone'))->isoFormat('LL');

    $this->actingAs(contentViewer(['pages.edit']));
    entryList(['tab' => 'trash'])
        ->assertSee('Binned page')
        ->assertSee(__('content.list.removed_on', ['date' => $removal]))
        ->call('restore', $entry->id)
        ->assertDontSee('Binned page');

    expect(Entry::find($entry->id))->not->toBeNull();
});

test('deleting for good is confirmed, states the subpages and needs pages.delete', function () {
    $parent = Entry::factory()->create(['title' => 'Old parent', 'slug' => 'old', 'path' => 'old']);
    Entry::factory()->childOf($parent)->count(2)->sequence(['slug' => 'a', 'path' => 'old/a'], ['slug' => 'b', 'path' => 'old/b'])->create();
    app(TrashEntry::class)->handle(User::factory()->withRole('editor')->create(), $parent);

    $this->actingAs(contentViewer(['pages.edit']));
    entryList(['tab' => 'trash'])->call('confirmDelete', $parent->id)->assertForbidden();

    $this->actingAs(contentViewer(['pages.delete']));
    entryList(['tab' => 'trash'])
        ->call('confirmDelete', $parent->id)
        ->assertSet('showDeleteModal', true)
        ->assertSee(trans_choice('content.list.delete.confirm_children', 2, ['title' => 'Old parent']))
        ->call('deleteForGood');

    expect(Entry::withTrashed()->count())->toBe(0);
});

test('a live record cannot be deleted for good from the list', function () {
    $entry = Entry::factory()->create();
    $this->actingAs(contentViewer(['pages.delete']));

    entryList()->call('confirmDelete', $entry->id)->assertNotFound();
});

test('the row menu offers only what the actor may do', function () {
    $page = Entry::factory()->published()->create(['title' => 'Public page']);

    $this->actingAs(contentViewer());
    entryList()
        ->assertSee($page->url(), false)
        ->assertDontSeeHtml('data-test="trash-entry-'.$page->id.'"')
        ->assertDontSeeHtml('data-test="edit-entry-menu-'.$page->id.'"');

    $this->actingAs(contentViewer(['pages.edit', 'pages.delete']));
    entryList()->assertSeeHtml('data-test="trash-entry-'.$page->id.'"')->assertSeeHtml('data-test="edit-entry-menu-'.$page->id.'"');
});

test('the title links to the edit page', function () {
    $this->actingAs(contentViewer());
    $page = Entry::factory()->create(['title' => 'Linked page']);

    entryList()->assertSee(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]), false);
});

test('empty states: first record, nothing matches, empty trash', function () {
    $this->actingAs(contentViewer());

    entryList()->assertSee(__('content.list.empty.title'));

    Entry::factory()->create(['title' => 'Something']);

    entryList()->set('search', 'nothing-like-this')
        ->assertSee(__('content.list.no_results.title'))
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('Something');

    entryList(['tab' => 'trash'])->assertSee(__('content.list.empty_trash.title'));
});

test('the search term is cut at 200 characters, from the URL and from the input', function () {
    $this->actingAs(contentViewer());
    $long = str_repeat('я', 250);

    entryList(['q' => $long])
        ->assertSet('search', str_repeat('я', 200))
        ->assertSeeHtml('maxlength="200"')
        ->set('search', $long.'x')
        ->assertSet('search', str_repeat('я', 200));
});

test('state, query string and page are read from the URL', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->inLocale('uk')->published()->create(['title' => 'Олена сторінка']);

    entryList(['tab' => 'published', 'q' => 'олена', 'locale' => 'uk', 'sort' => 'date', 'dir' => 'asc'])
        ->assertSet('tab', 'published')
        ->assertSet('search', 'олена')
        ->assertSet('sort', 'date')
        ->assertSet('dir', 'asc')
        ->assertSee('Олена сторінка');
});

test('the page makes the same number of queries with 5 and with 20 rows', function () {
    $this->actingAs(contentViewer(['pages.create', 'pages.edit', 'pages.delete']));

    $build = function (int $count): void {
        foreach (range(1, $count) as $index) {
            $media = Media::factory()->create();
            $author = User::factory()->create();
            $page = Entry::factory()->published()->create(['author_id' => $author->id, 'image_id' => $media->id]);
            Entry::factory()->translationOf($page, 'uk')->create();
        }
    };

    $measure = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        entryList();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // The first render warms what a process loads once (permissions, settings), whatever the rows.
    $measure();

    $build(5);
    $five = $measure();

    Entry::withTrashed()->forceDelete();
    $build(20);
    $twenty = $measure();

    expect($twenty)->toBe($five);
});

test('opening the list prunes the trash at most once an hour', function () {
    $this->withoutDefer();
    $this->actingAs(contentViewer());
    $old = Entry::factory()->create();
    $old->delete();
    Entry::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

    entryList();
    expect(Entry::withTrashed()->find($old->id))->toBeNull();

    $again = Entry::factory()->create();
    $again->delete();
    Entry::withTrashed()->whereKey($again->id)->update(['deleted_at' => now()->subDays(31)]);

    entryList();
    expect(Entry::withTrashed()->find($again->id))->not->toBeNull();

    Cache::flush();
    entryList();
    expect(Entry::withTrashed()->find($again->id))->toBeNull();
});

test('the create and edit pages exist and authorize', function () {
    $page = Entry::factory()->create();

    $this->actingAs(contentViewer());
    $this->get(route('admin.content.create', 'pages'))->assertForbidden();
    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))->assertOk();

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))->assertForbidden();

    $this->actingAs(contentViewer(['pages.create']));
    $this->get(route('admin.content.create', 'pages'))->assertOk();
});

test('picked rows go to the trash together, with their subpages, after a confirmation', function () {
    $parent = Entry::factory()->create(['title' => 'Parent', 'slug' => 'parent', 'path' => 'parent']);
    $child = Entry::factory()->childOf($parent)->create(['slug' => 'child', 'path' => 'parent/child']);
    $other = Entry::factory()->inLocale('uk')->create(['title' => 'Інша']);
    $kept = Entry::factory()->create(['title' => 'Kept']);

    $this->actingAs(contentViewer(['pages.edit']));
    entryList()
        ->assertSeeHtml('data-test="select-all"')
        ->assertDontSeeHtml('data-test="bulk-trash"')
        ->set('selected', [(string) $parent->id])
        ->call('confirmBulk', 'trash')
        ->assertForbidden();

    $this->actingAs(contentViewer(['pages.delete']));
    entryList()
        ->assertSeeHtml('data-test="select-entry-'.$parent->id.'"')
        ->set('selected', [(string) $parent->id, (string) $child->id, (string) $other->id])
        ->call('confirmBulk', 'trash')
        ->assertSet('showBulkModal', true)
        ->assertSee(trans_choice('content.list.bulk.trash_confirm', 3, ['days' => 30]))
        ->call('runBulk')
        ->assertSet('showBulkModal', false)
        ->assertSet('selected', []);

    expect(Entry::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe([$parent->id, $child->id, $other->id])
        ->and(Entry::find($kept->id))->not->toBeNull();
});

test('a bulk action with nothing picked opens no dialog', function () {
    $this->actingAs(contentViewer(['pages.delete']));

    entryList()->call('confirmBulk', 'trash')->assertSet('showBulkModal', false);
    entryList()->call('confirmBulk', 'publish')->assertNotFound();
});

test('picked rows are restored from the trash with pages.edit, a parent before its page', function () {
    $actor = User::factory()->withRole('editor')->create();
    $parent = Entry::factory()->create(['slug' => 'parent', 'path' => 'parent']);
    $child = Entry::factory()->childOf($parent)->create(['slug' => 'child', 'path' => 'parent/child']);
    $left = Entry::factory()->create(['title' => 'Left in the trash']);
    app(TrashEntry::class)->handle($actor, $child);
    $this->travel(5)->seconds();
    app(TrashEntry::class)->handle($actor, $parent);
    app(TrashEntry::class)->handle($actor, $left);

    $this->actingAs(contentViewer(['pages.delete']));
    entryList(['tab' => 'trash'])->set('selected', [(string) $child->id])->call('restoreSelected')->assertForbidden();

    $this->actingAs(contentViewer(['pages.edit']));
    entryList(['tab' => 'trash'])
        ->set('selected', [(string) $child->id, (string) $parent->id])
        ->call('restoreSelected')
        ->assertSet('selected', []);

    expect(Entry::find($parent->id))->not->toBeNull()
        ->and(Entry::find($child->id)->path)->toBe('parent/child')
        ->and(Entry::find($left->id))->toBeNull();
});

test('picked rows of the trash are deleted for good after a confirmation, live ones are left alone', function () {
    $actor = User::factory()->withRole('editor')->create();
    $binned = Entry::factory()->create();
    $kept = Entry::factory()->create();
    $live = Entry::factory()->create();
    app(TrashEntry::class)->handle($actor, $binned);
    app(TrashEntry::class)->handle($actor, $kept);

    $this->actingAs(contentViewer(['pages.delete']));
    entryList(['tab' => 'trash'])
        ->set('selected', [(string) $binned->id, (string) $live->id])
        ->call('confirmBulk', 'delete')
        ->assertSee(trans_choice('content.list.bulk.delete_confirm', 1))
        ->call('runBulk');

    expect(Entry::withTrashed()->pluck('id')->sort()->values()->all())->toBe([$kept->id, $live->id]);
});

test('emptying the trash deletes what the language filter shows and needs pages.delete', function () {
    $actor = User::factory()->withRole('editor')->create();
    $english = Entry::factory()->create();
    $ukrainian = Entry::factory()->inLocale('uk')->create();
    $live = Entry::factory()->create();
    app(TrashEntry::class)->handle($actor, $english);
    app(TrashEntry::class)->handle($actor, $ukrainian);

    $this->actingAs(contentViewer(['pages.edit']));
    entryList(['tab' => 'trash'])->assertDontSeeHtml('data-test="empty-trash"')->call('confirmBulk', 'empty')->assertForbidden();

    $this->actingAs(contentViewer(['pages.delete']));
    entryList(['tab' => 'trash', 'locale' => 'uk'])
        ->assertSeeHtml('data-test="empty-trash"')
        ->call('confirmBulk', 'empty')
        ->assertSee(trans_choice('content.list.bulk.empty_confirm', 1))
        ->call('runBulk')
        ->assertDontSeeHtml('data-test="empty-trash"');

    expect(Entry::withTrashed()->pluck('id')->sort()->values()->all())->toBe([$english->id, $live->id]);

    entryList(['tab' => 'trash', 'locale' => ''])->set('locale', '')->call('confirmBulk', 'empty')->call('runBulk');

    expect(Entry::withTrashed()->pluck('id')->all())->toBe([$live->id]);
});

test('changing the tab, the search or the language drops the picked rows', function (string $property, string $value) {
    $this->actingAs(contentViewer(['pages.delete']));
    $entry = Entry::factory()->create();

    entryList()->set('selected', [(string) $entry->id])->set($property, $value)->assertSet('selected', []);
})->with([['tab', 'drafts'], ['search', 'x'], ['locale', 'uk']]);

test('an action that empties the last page shows the page before it', function () {
    $this->actingAs(contentViewer(['pages.delete']));
    $perPage = app(ContentTypes::class)->get('pages')->perPage();
    Entry::factory()->count($perPage)->sequence(fn ($sequence) => ['title' => 'Page '.$sequence->index, 'slug' => 'p'.$sequence->index, 'path' => 'p'.$sequence->index])->create();
    $last = Entry::factory()->create(['title' => 'The last one', 'slug' => 'z', 'path' => 'z']);

    entryList()
        ->call('setPage', 2)
        ->assertSee('The last one')
        ->set('selected', [(string) $last->id])
        ->call('confirmBulk', 'trash')
        ->call('runBulk')
        ->assertSee('Page 0')
        ->assertDontSee(__('content.list.empty_tab.title'));
});

test('the bulk dialog counts the picked rows that exist, not the picks', function () {
    $this->actingAs(contentViewer(['pages.delete']));
    $entry = Entry::factory()->create();

    entryList()
        ->set('selected', [(string) $entry->id, '999', '1000'])
        ->call('confirmBulk', 'trash')
        ->assertSet('bulkCount', 1);

    entryList()->set('selected', ['999'])->call('confirmBulk', 'trash')->assertSet('showBulkModal', false);
    entryList()->call('runBulk')->assertSet('showBulkModal', false);
});

test('a row is published and taken off the site from its menu with pages.edit', function () {
    $this->travelTo('2026-03-04 10:00:00');
    $draft = Entry::factory()->draft()->create(['title' => 'A draft']);
    $scheduled = Entry::factory()->scheduled()->create();
    $live = Entry::factory()->published()->create(['published_at' => '2026-01-01 08:00:00']);

    $this->actingAs(contentViewer());
    entryList()
        ->assertDontSeeHtml('data-test="publish-entry-'.$draft->id.'"')
        ->call('setStatus', $draft->id, 'published')
        ->assertForbidden();

    $this->actingAs(contentViewer(['pages.edit']));
    entryList()
        ->assertSeeHtml('data-test="publish-entry-'.$draft->id.'"')
        ->assertDontSeeHtml('data-test="unpublish-entry-'.$draft->id.'"')
        ->assertSeeHtml('data-test="unpublish-entry-'.$live->id.'"')
        ->assertDontSeeHtml('data-test="publish-entry-'.$live->id.'"')
        ->call('setStatus', $draft->id, 'published')
        ->call('setStatus', $scheduled->id, 'published')
        ->call('setStatus', $live->id, 'draft')
        ->assertSeeHtml('data-test="unpublish-entry-'.$draft->id.'"');

    expect($draft->fresh())->isPublic()->toBeTrue()->title->toBe('A draft')
        ->and($draft->fresh()->published_at->toDateTimeString())->toBe('2026-03-04 10:00:00')
        ->and($scheduled->fresh()->isPublic())->toBeTrue()
        ->and($live->fresh())->status->toBe(EntryStatus::Draft)
        ->and($live->fresh()->published_at->toDateTimeString())->toBe('2026-01-01 08:00:00');

    entryList()->call('setStatus', $draft->id, 'archived')->assertNotFound();
});

test('picked rows are published and taken off the site together, never those in the trash', function () {
    $first = Entry::factory()->draft()->create();
    $second = Entry::factory()->draft()->create();
    $binned = Entry::factory()->draft()->create(['deleted_at' => now()]);

    $this->actingAs(contentViewer(['pages.delete']));
    entryList()
        ->assertDontSeeHtml('data-test="bulk-publish"')
        ->set('selected', [(string) $first->id])
        ->call('setStatusSelected', 'published')
        ->assertForbidden();

    $this->actingAs(contentViewer(['pages.edit']));
    entryList()
        ->assertSeeHtml('data-test="bulk-publish"')
        ->set('selected', [(string) $first->id, (string) $second->id, (string) $binned->id])
        ->call('setStatusSelected', 'published')
        ->assertSet('selected', []);

    expect($first->fresh()->isPublic())->toBeTrue()
        ->and($second->fresh()->isPublic())->toBeTrue()
        ->and(Entry::withTrashed()->find($binned->id)->status)->toBe(EntryStatus::Draft);

    entryList()->set('selected', [(string) $first->id])->call('setStatusSelected', 'draft');

    expect($first->fresh()->status)->toBe(EntryStatus::Draft);
});

test('every row shows its publication status', function () {
    $this->actingAs(contentViewer());
    $live = Entry::factory()->published()->create();
    $draft = Entry::factory()->draft()->create();
    $planned = Entry::factory()->scheduled()->create();
    $state = fn (Entry $entry, string $state): string => 'data-test="entry-state-'.$entry->id.'" data-state="'.$state.'"';

    entryList()
        ->assertSeeHtml([$state($live, 'published'), $state($draft, 'draft'), $state($planned, 'scheduled')])
        ->assertSee([__('content.list.state.published'), __('content.list.columns.status')]);

    $draft->delete();
    entryList(['tab' => 'trash'])->assertDontSeeHtml('data-test="entry-state-'.$draft->id.'"');
});

test('going back to the tree order and clearing the filters drop the picked rows too', function () {
    $this->actingAs(contentViewer(['pages.delete']));
    $entry = Entry::factory()->create();

    entryList()->call('sort', 'title')->set('selected', [(string) $entry->id])->call('resetSort')->assertSet('selected', []);
    entryList()->set('selected', [(string) $entry->id])->call('clearFilters')->assertSet('selected', []);
});
