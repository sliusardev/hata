<?php

use App\Models\User;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [
            ['code' => 'en', 'active' => true],
            ['code' => 'uk', 'active' => true],
            ['code' => 'pl', 'active' => true],
            ['code' => 'de', 'active' => false],
        ],
        'content_default_locale' => 'en',
    ]);
});

function formEditor(): User
{
    return User::factory()->withRole('editor')->create(['name' => 'Olena']);
}

/**
 * @param  array<string, string>  $query
 */
function createForm(array $query = ['locale' => 'en'], string $type = 'pages'): Testable
{
    return Livewire::withQueryParams($query)->test('hata::admin.content.create', ['type' => $type]);
}

function editForm(Entry $entry): Testable
{
    return Livewire::test('hata::admin.content.edit', ['type' => $entry->type, 'entry' => $entry]);
}

test('a page is created through the form', function () {
    $this->actingAs($editor = formEditor());

    $form = createForm()
        ->set('form.title', 'About us')
        ->set('form.slug', 'about-us')
        ->set('form.excerpt', 'Who we are')
        ->set('form.body', '<p>Hello</p>')
        ->call('save')
        ->assertHasNoErrors();

    $entry = Entry::query()->where('path', 'about-us')->firstOrFail();

    $form->assertRedirect(route('admin.content.edit', ['type' => 'pages', 'entry' => $entry->id]));

    expect($entry->title)->toBe('About us')
        ->and($entry->locale)->toBe('en')
        ->and($entry->excerpt)->toBe('Who we are')
        ->and($entry->body)->toBe('<p>Hello</p>')
        ->and($entry->author_id)->toBe($editor->id)
        ->and($entry->status)->toBe(EntryStatus::Published)
        ->and($entry->isPublic())->toBeTrue()
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('hata::content.form.created'));
});

test('the new form opens in the requested language and refuses a language that is not on the site', function () {
    $this->actingAs(formEditor());

    $html = $this->get(route('admin.content.create', ['type' => 'pages', 'locale' => 'uk']))
        ->assertOk()
        ->assertSee(__('hata::content.form.language.choose'))
        ->getContent();

    expect($html)->toMatch('/<option(?=[^>]*value="uk")[^>]*\sselected/')
        ->and($html)->not->toMatch('/<option(?=[^>]*value="en")[^>]*\sselected/');

    $this->get(route('admin.content.create', ['type' => 'pages', 'locale' => 'xx']))->assertNotFound();
    $this->get(route('admin.content.create', ['type' => 'pages']))->assertOk();

    createForm(['locale' => 'pl'])->assertSet('locale', 'pl');
});

test('the page title names the type and the record', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->create(['title' => 'Contacts']);

    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))
        ->assertOk()
        ->assertSeeInOrder(['<title>', __('hata::content.form.page_title', ['type' => 'Page', 'title' => 'Contacts']), '</title>'], false);

    $this->get(route('admin.content.create', ['type' => 'pages', 'locale' => 'en']))
        ->assertSee(__('hata::content.types.pages.new'));
});

test('suggestSlug returns a free slug, a suffixed one when it is taken, and follows the parent', function () {
    $this->actingAs(formEditor());
    $about = Entry::factory()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    Entry::factory()->create(['slug' => 'our-team', 'path' => 'our-team']);

    $form = createForm()->set('form.title', 'Our team');

    expect($form->instance()->suggestSlug())->toBe('our-team-2');

    $form->set('form.parent_id', (string) $about->id);
    expect($form->instance()->suggestSlug())->toBe('our-team');

    $form->set('form.title', 'Brand new');
    expect($form->instance()->suggestSlug())->toBe('brand-new');
});

test('suggestSlug only returns the slug, so a slug typed meanwhile is kept', function () {
    $this->actingAs(formEditor());

    createForm()
        ->set('form.title', 'Our team')
        ->set('form.slug', 'typed-by-hand')
        ->call('suggestSlug')
        ->assertSet('form.slug', 'typed-by-hand');
});

test('editing changes the title, the parent and the slug', function () {
    $this->actingAs(formEditor());
    $company = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    $page = Entry::factory()->create(['title' => 'Team', 'slug' => 'team', 'path' => 'team']);

    editForm($page)
        ->assertSet('form.title', 'Team')
        ->assertSet('form.slug', 'team')
        ->set('form.title', 'Our people')
        ->set('form.parent_id', (string) $company->id)
        ->set('form.slug', 'people')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('entry-saved', locale: 'en', state: 'draft')
        ->assertSee('Our people');

    $page->refresh();

    expect($page->title)->toBe('Our people')
        ->and($page->parent_id)->toBe($company->id)
        ->and($page->path)->toBe('company/people');
});

test('validation errors stay on their fields', function () {
    $this->actingAs(formEditor());
    Entry::factory()->create(['slug' => 'taken', 'path' => 'taken']);

    createForm()
        ->set('form.title', '')
        ->set('form.slug', 'taken')
        ->set('form.body', '<p>Kept</p>')
        ->call('save')
        ->assertHasErrors(['form.title', 'form.slug'])
        ->assertSet('form.body', '<p>Kept</p>')
        ->assertDispatched('entry-form-invalid')
        ->assertNoRedirect();

    expect(Entry::query()->count())->toBe(1);
});

test('the parent select offers the tree without the record itself and its descendants', function () {
    $this->actingAs(formEditor());
    $about = Entry::factory()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    $team = Entry::factory()->childOf($about)->create(['title' => 'Team', 'slug' => 'team', 'path' => 'about/team']);
    $lead = Entry::factory()->childOf($team)->create(['title' => 'Lead', 'slug' => 'lead', 'path' => 'about/team/lead']);
    $contacts = Entry::factory()->create(['title' => 'Contacts', 'slug' => 'contacts', 'path' => 'contacts']);
    $other = Entry::factory()->inLocale('uk')->create(['title' => 'Ukrainian only']);

    editForm($team)
        ->assertSeeHtml('data-test="parent-option-'.$about->id.'"')
        ->assertSeeHtml('data-test="parent-option-'.$contacts->id.'"')
        ->assertDontSeeHtml('data-test="parent-option-'.$team->id.'"')
        ->assertDontSeeHtml('data-test="parent-option-'.$lead->id.'"')
        ->assertDontSeeHtml('data-test="parent-option-'.$other->id.'"');

    createForm()->assertSeeInOrder(['About', 'Team', 'Lead', 'Contacts']);
});

test('a tampered parent is a field error, and so is a language removed before saving', function () {
    $this->actingAs(formEditor());
    $foreign = Entry::factory()->inLocale('uk')->create();
    $page = Entry::factory()->create(['slug' => 'page', 'path' => 'page']);

    editForm($page)
        ->set('form.parent_id', (string) $foreign->id)
        ->call('save')
        ->assertHasErrors('form.parent_id');

    editForm($page)->set('form.parent_id', (string) $page->id)->call('save')->assertHasErrors('form.parent_id');

    $form = createForm(['locale' => 'pl'])->set('form.title', 'Polish')->set('form.slug', 'polish');

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    $form->call('save')
        ->assertHasErrors('form.locale')
        ->assertSeeHtml('data-test="form-error-callout"')
        ->assertSee(__('hata::content.entry.locale_invalid'));
    expect(Entry::query()->where('locale', 'pl')->exists())->toBeFalse();
});

test('a translation is filled from its source and saved into the same group', function () {
    $this->actingAs(formEditor());
    $parent = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    $parentUk = Entry::factory()->translationOf($parent, 'uk')->create(['slug' => 'kompaniia', 'path' => 'kompaniia']);
    $source = Entry::factory()->childOf($parent)->published()->create([
        'title' => 'Our history', 'slug' => 'history', 'path' => 'company/history', 'excerpt' => 'Since 1990',
    ]);

    createForm(['from' => (string) $source->id, 'locale' => 'uk'])
        ->assertSet('locale', 'uk')
        ->assertSet('form.title', 'Our history')
        ->assertSet('form.excerpt', 'Since 1990')
        ->assertSet('form.parent_id', (string) $parentUk->id)
        ->assertSet('form.slug', 'our-history')
        ->assertSet('form.status', 'published')
        ->set('form.title', 'Наша історія')
        ->call('save')
        ->assertHasNoErrors();

    $translation = Entry::query()->where('locale', 'uk')->where('title', 'Наша історія')->firstOrFail();

    expect($translation->entry_group_id)->toBe($source->entry_group_id)
        ->and($translation->path)->toBe('kompaniia/our-history');
});

test('opening a translation the group already has goes to its edit page, also when it is in the trash', function () {
    $this->actingAs(formEditor());
    $source = Entry::factory()->create();
    $existing = Entry::factory()->translationOf($source, 'uk')->create();
    $trashed = Entry::factory()->translationOf($source, 'pl')->create(['deleted_at' => now()]);

    $this->get(route('admin.content.create', ['type' => 'pages', 'from' => $source->id, 'locale' => 'uk']))
        ->assertRedirect(route('admin.content.edit', ['type' => 'pages', 'entry' => $existing->id]));

    $this->get(route('admin.content.create', ['type' => 'pages', 'from' => $source->id, 'locale' => 'pl']))
        ->assertRedirect(route('admin.content.edit', ['type' => 'pages', 'entry' => $trashed->id]));

    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $trashed->id]))
        ->assertOk()
        ->assertSee(__('hata::content.form.trashed.title'))
        ->assertSeeHtml('data-test="restore-entry"');

    $this->get(route('admin.content.create', ['type' => 'pages', 'from' => 999999, 'locale' => 'uk']))->assertNotFound();
});

test('with view only the form is read-only and refuses saving and trashing', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    $page = Entry::factory()->create(['title' => 'Read me']);

    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))
        ->assertOk()
        ->assertSee('Read me')
        ->assertDontSeeHtml('data-test="save-entry"')
        ->assertDontSeeHtml('data-test="trash-entry"')
        ->assertSeeHtml('data-readonly="true"');

    editForm($page)->set('form.title', 'Changed')->call('save')->assertForbidden();
    editForm($page)->call('confirmTrash')->assertForbidden();

    expect($page->fresh()->title)->toBe('Read me');
});

test('a creator without edit saves drafts only and sees the author as text', function () {
    $creator = User::factory()->withPermissions(['admin.access', 'pages.view', 'pages.create'])->create(['name' => 'Taras']);
    $this->actingAs($creator);

    $this->get(route('admin.content.create', ['type' => 'pages', 'locale' => 'en']))
        ->assertSeeHtml('data-test="status-locked"')
        ->assertSeeHtml('data-test="author-readonly"')
        ->assertSee('Taras');

    createForm()->set('form.title', 'Draft')->set('form.slug', 'draft')->set('form.status', 'published')
        ->call('save')
        ->assertHasErrors('form.status');
});

test('moving to the trash asks first, names the subpages and returns to the list', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    Entry::factory()->childOf($page)->count(2)->sequence(['slug' => 'a', 'path' => 'about/a'], ['slug' => 'b', 'path' => 'about/b'])->create();

    editForm($page)
        ->assertSet('showTrashModal', false)
        ->call('confirmTrash')
        ->assertSet('showTrashModal', true)
        ->assertSee(trans_choice('hata::content.list.trash.confirm_children', 2, ['title' => 'About']))
        ->call('trash')
        ->assertRedirect(route('admin.content.index', ['type' => 'pages']));

    expect(Entry::onlyTrashed()->count())->toBe(3)
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(trans_choice('hata::content.list.trash.done_with_children', 3));
});

test('a trashed record opens read-only with the removal date and can be restored with edit', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->create(['title' => 'Old', 'deleted_at' => now()->subDays(2)]);
    $removal = $page->deleted_at->copy()->addDays((int) config('hata.content.trash_days'))->tz(config('hata.admin.timezone'))->isoFormat('LL');

    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))
        ->assertOk()
        ->assertSee(__('hata::content.form.trashed.removal', ['date' => $removal]))
        ->assertDontSeeHtml('data-test="save-entry"')
        ->assertDontSeeHtml('data-test="trash-entry"');

    editForm($page)->call('save')->assertForbidden();

    editForm($page)
        ->call('restore')
        ->assertRedirect(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]));

    expect($page->fresh()->trashed())->toBeFalse();

    $page->delete();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))
        ->assertSee(__('hata::content.form.trashed.title'))
        ->assertDontSeeHtml('data-test="restore-entry"');
    editForm($page)->call('restore')->assertForbidden();
});

test('a restore refused by a conflict leaves the form showing the record in the trash', function () {
    $this->actingAs(formEditor());
    $parent = Entry::factory()->create(['slug' => 'about', 'path' => 'about', 'deleted_at' => now()->subDay()]);
    $child = Entry::factory()->childOf($parent)->create(['slug' => 'team', 'path' => 'about/team', 'deleted_at' => now()->subDays(2)]);

    // Another request takes each suggested top-level path before the restore writes it.
    $busy = false;
    DB::listen(function (QueryExecuted $query) use (&$busy): void {
        if ($busy || ! in_array('team-%', $query->bindings, true)) {
            return;
        }

        $busy = true;
        $taken = Entry::query()->where('path', 'like', 'team%')->count();
        Entry::factory()->create(['slug' => $taken === 0 ? 'team' : 'team-'.($taken + 1), 'path' => $taken === 0 ? 'team' : 'team-'.($taken + 1)]);
        $busy = false;
    });

    editForm($child)
        ->call('restore')
        ->assertNoRedirect()
        ->assertSeeHtml('data-test="trashed-callout"')
        ->assertSeeHtml('data-readonly="true"');

    expect(Entry::withTrashed()->find($child->id)->trashed())->toBeTrue();
});

test('a record of another type is 404', function () {
    app(ContentTypes::class)->register(new ProductsType);
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('super-admin')->create());
    $page = Entry::factory()->create();

    $this->get('/admin/content/products/'.$page->id.'/edit')->assertNotFound();
});

test('the header links to the site: View on site for a public record, Preview for a draft', function () {
    $this->actingAs(formEditor());
    $public = Entry::factory()->published()->create();
    $draft = Entry::factory()->create();

    editForm($public)->assertSee(__('hata::content.form.view'))->assertSeeHtml('href="'.$public->url().'"');
    editForm($draft)->assertSee(__('hata::content.form.preview'))->assertDontSee(__('hata::content.form.view'));
    createForm()->assertDontSeeHtml('data-test="view-entry"');
});

test('Preview shows only to those who may preview, and a record in a switched-off language has a note with it', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    $public = Entry::factory()->published()->create();
    $draft = Entry::factory()->create();
    $off = Entry::factory()->published()->inLocale('de')->create();

    editForm($public)->assertSeeHtml('data-test="view-entry"');
    editForm($draft)->assertDontSeeHtml('data-test="view-entry"')->assertDontSee(__('hata::content.form.preview'));

    $this->actingAs(formEditor());

    // In a switched-off language an editor gets the note and a preview; a view-only user the note alone.
    editForm($off)
        ->assertSeeHtml('data-test="view-entry"')
        ->assertSee(__('hata::content.form.preview'))
        ->assertSeeHtml('data-test="language-off-note"')
        ->assertSee(__('hata::content.form.language_off'));

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    editForm($off)->assertDontSeeHtml('data-test="view-entry"')->assertSeeHtml('data-test="language-off-note"');
    $this->actingAs(formEditor());

    $this->get(route('admin.content.index', ['type' => 'pages', 'locale' => 'de']))
        ->assertDontSeeHtml('data-test="view-entry-'.$off->id.'"')
        ->assertSeeHtml('data-test="language-off-note-'.$off->id.'"');
    $this->get(route('admin.content.index', ['type' => 'pages', 'locale' => 'en']))
        ->assertSeeHtml('data-test="view-entry-'.$public->id.'"');
});

test('a type with fields renders one control per field and stores local and shared values in their place', function () {
    app(ContentTypes::class)->register(new ProductsType);
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('super-admin')->create());
    $cover = Media::factory()->create();

    $form = createForm(['locale' => 'en'], 'products');

    foreach ((new ProductsType)->fields() as $field) {
        $form->assertSeeHtml('data-test="field-'.$field->name.'"');
    }

    $form->assertSee(__('hata::content.form.shared.title'))
        ->set('form.title', 'Chair')
        ->set('form.slug', 'chair')
        ->set('form.fields.subtitle', 'Oak')
        ->set('form.fields.price', '120')
        ->set('form.fields.featured', true)
        ->set('form.fields.size', 'l')
        ->set('form.fields.released', '2026-01-02')
        ->set('form.fields.cover', $cover->id)
        ->call('save')
        ->assertHasNoErrors();

    $entry = Entry::query()->where('type', 'products')->firstOrFail();

    expect($entry->fields)->toMatchArray(['subtitle' => 'Oak', 'featured' => true, 'size' => 'l', 'released' => '2026-01-02', 'cover' => $cover->id])
        ->and($entry->fields)->not->toHaveKey('price')
        ->and($entry->group->shared)->toBe(['price' => 120]);

    createForm(['locale' => 'en'], 'products')
        ->set('form.title', 'Table')
        ->set('form.slug', 'table')
        ->set('form.fields.price', 'cheap')
        ->call('save')
        ->assertHasErrors('form.fields.price');
});

test('a creator without edit sees the shared values locked in a translation, and saving it keeps them', function () {
    app(ContentTypes::class)->register(new ProductsType);
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'products.view', 'products.create'])->create());
    $source = Entry::factory()->inLocale('uk')->create(['type' => 'products', 'slug' => 'stil', 'path' => 'stil']);
    $source->group->update(['type' => 'products', 'shared' => ['price' => 10]]);

    $own = createForm(['locale' => 'en'], 'products')->assertDontSeeHtml('data-test="shared-locked"');

    $priceDisabled = fn (Testable $form): bool => (bool) preg_match('/<input\b[^>]*\sdisabled(?=[\s>="])/', Str::of($form->html())->after('data-test="field-price"')->before('</ui-field>')->toString());

    expect($priceDisabled($own))->toBeFalse();

    $form = createForm(['locale' => 'en', 'from' => (string) $source->id], 'products')
        ->assertSeeHtml('data-test="shared-locked"')
        ->assertSee(__('hata::content.form.shared.locked'));

    expect($priceDisabled($form))->toBeTrue();

    $form->set('form.title', 'Table')->set('form.slug', 'table')->set('form.fields.price', '99')
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->where('locale', 'en')->where('type', 'products')->exists())->toBeTrue()
        ->and($source->group->fresh()->shared)->toBe(['price' => 10]);
});

test('the pages type has no field cards', function () {
    $this->actingAs(formEditor());

    createForm()->assertDontSee(__('hata::content.form.shared.title'))->assertDontSee(__('hata::content.form.fields_card'));
});

test('the language card lists a row per other language: the translation, the trashed one, or the ways to get one', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->published()->create(['title' => 'Home']);
    $uk = Entry::factory()->translationOf($page, 'uk')->create(['title' => 'Головна']);
    $pl = Entry::factory()->translationOf($page, 'pl')->create(['deleted_at' => now()]);

    editForm($page)
        ->assertDontSeeHtml('data-test="translation-row-en"')
        ->assertSeeHtml('data-test="translation-row-uk" data-state="Draft"')
        ->assertSeeHtml('data-test="translation-row-pl" data-state="trashed"')
        ->assertSeeHtml('data-test="translation-row-de" data-state="missing"')
        ->assertSee('Головна')
        ->assertSee(route('admin.content.edit', ['type' => 'pages', 'entry' => $uk->id]), false)
        ->assertSee(route('admin.content.edit', ['type' => 'pages', 'entry' => $pl->id]), false)
        ->assertSee(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'de']))
        ->assertSeeHtml('data-test="translation-unlink-uk"')
        ->assertDontSeeHtml('data-test="translation-unlink-pl"')
        ->assertSeeHtml('data-test="translation-choose-de"')
        ->assertDontSeeHtml('data-test="translation-choose-pl"');

    editForm($uk)->assertSeeHtml('data-test="translation-row-en" data-state="Published"');
});

test('with view only the language card offers no choosing, creating or unlinking', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    $page = Entry::factory()->create();
    Entry::factory()->translationOf($page, 'uk')->create();

    editForm($page)
        ->assertSeeHtml('data-test="translation-row-uk"')
        ->assertDontSeeHtml('data-test="translation-unlink-uk"')
        ->assertDontSeeHtml('data-test="translation-choose-pl"')
        ->assertDontSeeHtml('data-test="translation-create-pl"')
        ->assertDontSeeHtml('data-test="entry-locale"');
});

test('the language card is hidden on a site with one language', function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);
    $this->actingAs(formEditor());

    editForm(Entry::factory()->create())->assertDontSeeHtml('data-test="translations-card"');
});

test('the editor gets the body images and the page mounts one detached picker', function () {
    $this->actingAs(formEditor());
    $image = Media::factory()->create();
    $page = Entry::factory()->create(['body' => '<p>a</p><img data-media-id="'.$image->id.'" alt="">']);

    editForm($page)
        ->assertSeeLivewire('hata::media-picker')
        ->assertSee(Str::afterLast($image->url(), '/'), false);

    $html = $this->get(route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]))->getContent();
    expect(substr_count($html, 'data-test="media-picker-detached"'))->toBe(1);
});

test('opening the form costs the same number of queries whatever the translations and pages', function () {
    $this->actingAs(formEditor());

    // A fresh instance each time, as the route binds one per request.
    $measure = function (Entry $entry): int {
        $entry = $entry->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        editForm($entry);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $small = Entry::factory()->create();
    Entry::factory()->translationOf($small, 'uk')->create();
    Entry::factory()->create();
    $measure($small);
    $few = $measure($small);

    $large = Entry::factory()->create();
    foreach (['uk', 'pl', 'de'] as $locale) {
        Entry::factory()->translationOf($large, $locale)->create();
    }
    Entry::factory()->count(10)->create();
    $many = $measure($large);

    expect($many)->toBe($few);

    $translate = function (Entry $source): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        createForm(['from' => (string) $source->id, 'locale' => 'pl']);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $smallSource = Entry::factory()->create();
    $translate($smallSource);
    $fewTranslations = $translate($smallSource);

    $largeSource = Entry::factory()->create();
    foreach (['uk', 'de'] as $locale) {
        Entry::factory()->translationOf($largeSource, $locale)->create();
    }
    Entry::factory()->count(10)->create();

    expect($translate($largeSource))->toBe($fewTranslations);

    $withImages = function (int $count) use ($measure): int {
        $body = Media::factory()->image()->count($count)->create()
            ->map(fn (Media $media): string => '<img data-media-id="'.$media->id.'" alt="">')->implode('');

        return $measure(Entry::factory()->create(['body' => '<p>a</p>'.$body]));
    };

    $withImages(1);
    expect($withImages(5))->toBe($withImages(1));
});

test('the media library links a record using a file to its edit page, also from the trash', function () {
    $editor = formEditor();
    $image = Media::factory()->create();
    $page = Entry::factory()->create(['image_id' => $image->id]);
    MediaUsage::sync($page, 'image', [$image->id]);
    $usage = MediaUsage::query()->where('media_id', $image->id)->firstOrFail();
    $edit = route('admin.content.edit', ['type' => 'pages', 'entry' => $page->id]);

    expect($usage->url($editor))->toBe($edit)
        ->and($usage->url(User::factory()->withPermissions(['admin.access'])->create()))->toBeNull();

    $page->delete();
    expect($usage->fresh()->url($editor))->toBe($edit);

    $this->actingAs($editor)->get($edit)->assertOk()->assertSee(__('hata::content.form.trashed.title'));
});

test('a translation whose copied author can no longer be one is the actor\'s', function () {
    $this->actingAs($editor = formEditor());
    $former = User::factory()->withPermissions(['admin.access'])->create();
    $source = Entry::factory()->create(['author_id' => $former->id]);
    $kept = Entry::factory()->create(['author_id' => ($other = formEditor())->id]);

    createForm(['from' => (string) $source->id, 'locale' => 'uk'])->assertSet('form.author_id', (string) $editor->id);
    createForm(['from' => (string) $kept->id, 'locale' => 'uk'])->assertSet('form.author_id', (string) $other->id);
});

test('moving a record that is already in the trash reports nothing', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->create();

    $form = editForm($page)->call('confirmTrash');
    $page->delete();

    $form->call('trash')->assertNoRedirect()->assertSet('showTrashModal', false);
    expect(session(Toast::SESSION_KEY))->toBeNull();
});

test('a translation whose source went to the trash meanwhile is not saved on its own', function () {
    $this->actingAs(formEditor());
    $source = Entry::factory()->create();

    $form = createForm(['from' => (string) $source->id, 'locale' => 'uk'])->set('form.slug', 'copy');
    $source->delete();

    $form->call('save')
        ->assertHasErrors('form.source')
        ->assertSee(__('hata::content.form.source_gone'))
        ->assertNoRedirect();

    expect(Entry::query()->where('locale', 'uk')->exists())->toBeFalse();
});

test('the address prefix carries the parent path, and a trashed child shows its trashed parent', function () {
    $this->actingAs(formEditor());
    $about = Entry::factory()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);
    $team = Entry::factory()->childOf($about)->create(['title' => 'Team', 'slug' => 'team', 'path' => 'about/team']);

    editForm($team)->assertSeeHtml('about/</bdi>');

    $about->delete();
    $team->delete();

    editForm($team->fresh())
        ->assertSeeHtml('data-test="parent-option-'.$about->id.'"')
        ->assertSet('form.parent_id', (string) $about->id)
        ->assertSeeHtml('about/</bdi>');
});

test('a new record offers the languages of the site, and a saved one those its group lacks', function () {
    $this->actingAs(formEditor());

    createForm(['locale' => 'uk'])
        ->assertSeeHtml('data-test="entry-locale"')
        ->assertSeeInOrder(['English (EN)', 'Українська (UK)', 'Polski (PL)', 'Deutsch (DE) · off']);

    $page = Entry::factory()->create();
    Entry::factory()->translationOf($page, 'uk')->create();
    Entry::factory()->translationOf($page, 'pl')->create(['deleted_at' => now()]);

    editForm($page)
        ->assertSeeHtml('data-test="entry-locale"')
        ->assertSeeHtml(['data-test="locale-choice-en" data-taken="false"', 'data-test="locale-choice-de" data-taken="false"'])
        ->assertSeeHtml(['data-test="locale-choice-uk" data-taken="true"', 'data-test="locale-choice-pl" data-taken="true"'])
        ->assertSee(__('hata::content.form.language.taken'));
});

test('a new record starts as published for those who may publish', function () {
    $this->actingAs(formEditor());

    createForm()->assertSet('form.status', 'published');
});

test('choosing another language changes the new record in place and keeps what was typed', function () {
    $this->actingAs(formEditor());
    $parent = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    Entry::factory()->inLocale('uk')->create(['slug' => 'our-team', 'path' => 'our-team']);
    $ukParent = Entry::factory()->inLocale('uk')->create(['title' => 'Компанія', 'slug' => 'kompaniia', 'path' => 'kompaniia']);

    $form = createForm()
        ->set('form.title', 'Our team')
        ->set('form.slug', 'our-team')
        ->set('form.body', '<p>We are five.</p>')
        ->set('form.parent_id', (string) $parent->id)
        ->call('changeLocale', 'uk')
        ->assertNoRedirect()
        ->assertSet('locale', 'uk')
        ->assertSet('form.title', 'Our team')
        ->assertSet('form.body', '<p>We are five.</p>')
        ->assertSet('form.parent_id', '')
        ->assertSet('form.slug', 'our-team-2')
        ->assertSee('Компанія')
        ->assertReturned(fn (array $state): bool => $state['locale'] === 'uk'
            && str_ends_with($state['prefix'], '/uk/')
            && ! isset($state['parentPaths']))
        ->assertSeeHtml('data-path="kompaniia" data-test="parent-option-'.$ukParent->id.'"')
        ->assertDontSeeHtml('data-test="parent-option-'.$parent->id.'"');

    $form->call('save')->assertHasNoErrors();

    expect(Entry::query()->where('slug', 'our-team-2')->sole()->locale)->toBe('uk');
});

test('a language that is not offered is ignored', function (string $locale) {
    $this->actingAs(formEditor());

    createForm()
        ->set('form.title', 'Team')
        ->call('changeLocale', $locale)
        ->assertReturned(null)
        ->assertSet('locale', 'en');
})->with(['not on the site' => ['fr'], 'the form\'s own' => ['en']]);

test('a translation offers only the languages its group lacks and takes the parent that language has', function () {
    $this->actingAs(formEditor());
    $company = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    $firma = Entry::factory()->translationOf($company, 'de')->create(['slug' => 'firma', 'path' => 'firma']);
    $about = Entry::factory()->childOf($company)->create(['title' => 'About', 'slug' => 'about', 'path' => 'company/about']);
    Entry::factory()->translationOf($about, 'pl')->create();

    $form = createForm(['locale' => 'uk', 'from' => (string) $about->id])
        ->assertSeeHtml(['data-test="locale-choice-uk" data-taken="false"', 'data-test="locale-choice-de" data-taken="false"'])
        ->assertSeeHtml(['data-test="locale-choice-en" data-taken="true"', 'data-test="locale-choice-pl" data-taken="true"'])
        ->assertSet('form.parent_id', '');

    $form->call('changeLocale', 'pl')->assertReturned(null)->assertSet('locale', 'uk');

    $form->set('form.title', 'Über uns')
        ->call('changeLocale', 'de')
        ->assertSet('locale', 'de')
        ->assertSet('sourceId', $about->id)
        ->assertSet('form.title', 'Über uns')
        ->assertSet('form.parent_id', (string) $firma->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->where('title', 'Über uns')->sole())
        ->locale->toBe('de')
        ->entry_group_id->toBe($about->entry_group_id)
        ->path->toBe('firma/about');
});

test('changing the language needs the right to create', function () {
    $this->actingAs(formEditor());
    $form = createForm();

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    $form->call('changeLocale', 'uk')->assertForbidden();
});

test('publishing without a date stays on the page and shows the date the record got', function () {
    $this->actingAs(formEditor());
    $this->travelTo('2026-03-04 10:00:00');
    $page = Entry::factory()->draft()->create();

    editForm($page)
        ->set('form.status', 'published')
        ->call('save')
        ->assertNoRedirect()
        ->assertDispatched('entry-saved', state: 'published')
        ->assertSet('form.published_at', '2026-03-04T10:00');
});

test('the header lists the languages of the page as links, and a translation says it is a copy', function () {
    $this->actingAs(formEditor());
    $page = Entry::factory()->published()->create(['title' => 'Home']);
    $uk = Entry::factory()->translationOf($page, 'uk')->create();

    $header = Str::of(editForm($page)->assertSeeHtml('data-test="header-translations"')->assertDontSeeHtml('data-test="translation-callout"')->html())
        ->after('data-test="header-translations"')->before('data-test="save-entry"')->toString();

    expect($header)->toContain('data-test="chip-en" data-state="this"')
        ->toContain('data-test="chip-uk" data-state="draft"')
        ->toContain('data-test="chip-pl" data-state="missing"')
        ->toContain(route('admin.content.edit', ['type' => 'pages', 'entry' => $uk->id]))
        ->toContain(e(route('admin.content.create', ['type' => 'pages', 'from' => $page->id, 'locale' => 'pl'])));

    createForm()->assertDontSeeHtml('data-test="header-translations"')->assertDontSeeHtml('data-test="translation-callout"');

    createForm(['from' => (string) $page->id, 'locale' => 'pl'])
        ->assertSeeHtml('data-test="translation-callout"')
        ->assertSee(__('hata::content.form.translation.title', ['title' => 'Home', 'language' => 'EN']))
        ->assertSeeHtml('data-test="chip-en" data-state="published"');
});
