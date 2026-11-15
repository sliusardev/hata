<?php

use App\Actions\Content\TrashEntry;
use App\Enums\EntryStatus;
use App\Models\Entry;
use App\Models\Media;
use App\Models\User;
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

    $list = entryList();

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

test('rows are indented only on the all tab without a search or a missing filter', function () {
    $this->actingAs(contentViewer());
    $about = Entry::factory()->published()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    $team = Entry::factory()->childOf($about)->published()->create(['title' => 'Team', 'slug' => 'team', 'path' => 'about/team']);
    $about->update(['status' => EntryStatus::Draft]);

    entryList()->assertSeeHtml('data-depth="1" data-test="entry-row-'.$team->id.'"');
    entryList(['tab' => 'published'])->assertSeeHtml('data-depth="0" data-test="entry-row-'.$team->id.'"');
    entryList(['missing' => 'uk'])->assertSeeHtml('data-depth="0" data-test="entry-row-'.$team->id.'"');
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

test('missing=uk lists only records without a uk translation', function () {
    $this->actingAs(contentViewer());
    $translated = Entry::factory()->create(['title' => 'Has Ukrainian']);
    Entry::factory()->translationOf($translated, 'uk')->create(['title' => 'Є українською']);
    Entry::factory()->create(['title' => 'Needs Ukrainian']);
    $trashedOnly = Entry::factory()->create(['title' => 'Ukrainian in trash']);
    Entry::factory()->translationOf($trashedOnly, 'uk')->create()->delete();

    entryList(['missing' => 'uk'])
        ->assertSet('missing', 'uk')
        ->assertSee(['Needs Ukrainian', 'Ukrainian in trash'])
        ->assertDontSee('Has Ukrainian');
});

test('the list shows one language: the default, then the chosen one, remembered in the session', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'English page']);
    Entry::factory()->inLocale('uk')->create(['title' => 'Українська сторінка']);

    entryList()->assertSet('locale', 'en')->assertSee('English page')->assertDontSee('Українська сторінка');

    entryList()->set('locale', 'uk')->assertSee('Українська сторінка')->assertDontSee('English page');
    expect(session('content.list_locale'))->toBe('uk');

    entryList()->assertSet('locale', 'uk')->assertSee('Українська сторінка');
});

test('a locale that is not on the site falls back to the default', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create(['title' => 'English page']);

    entryList(['locale' => 'de'])->assertSet('locale', 'en')->assertSee('English page');
    entryList()->set('locale', 'xx')->assertSet('locale', 'en');
});

test('the translations bar counts every language of the site, the switched-off ones too', function () {
    $this->actingAs(contentViewer(['pages.create']));
    $page = Entry::factory()->published()->create(['title' => 'Group page']);
    Entry::factory()->translationOf($page, 'uk')->create();

    entryList()
        ->assertSeeHtml('data-test="translations-'.$page->id.'"')
        ->assertSee('2/3')
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
        ->assertDontSeeHtml('data-test="list-missing"')
        ->assertDontSeeHtml('data-test="translations-'.$page->id.'"')
        ->assertDontSee(__('content.list.columns.translations'));
});

test('two language site shows the language controls', function () {
    $this->actingAs(contentViewer());
    Entry::factory()->create();

    entryList()->assertSeeHtml('data-test="list-locale"')->assertSeeHtml('data-test="list-missing"');
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
