<?php

use App\Models\User;
use Hata\Actions\Content\DeleteEntry;
use Hata\Actions\Content\RestoreEntry;
use Hata\Actions\Content\SetEntryStatus;
use Hata\Actions\Content\TrashEntry;
use Hata\Actions\Content\UpdateEntry;
use Hata\Actions\Settings\SetContentTypeActive;
use Hata\Enums\EntryStatus;
use Hata\Livewire\Admin\EntryListPage;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    // The suite runs with the pages type alone; these are the kit's posts with their taxonomies.
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType)->register(new TagsType);
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    $this->admin = User::factory()->withRole('admin')->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function term(string $type, string $title, array $attributes = []): Entry
{
    $slug = Str::slug($title);

    return Entry::factory()->published()->create(['type' => $type, 'title' => $title, 'slug' => $slug, 'path' => $slug, ...$attributes]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function post(string $title, array $attributes = []): Entry
{
    $slug = Str::slug($title);

    return Entry::factory()->published()->create(['type' => 'posts', 'title' => $title, 'slug' => $slug, 'path' => $slug, ...$attributes]);
}

function postForm(?Entry $post = null, string $locale = 'en'): Testable
{
    return $post === null
        ? Livewire::withQueryParams(['locale' => $locale])->test('hata::admin.content.create', ['type' => 'posts'])
        : Livewire::test('hata::admin.content.edit', ['type' => 'posts', 'entry' => $post]);
}

test('categories and tags are taxonomies of the posts and go on and off with them', function () {
    $types = app(ContentTypes::class);

    expect(array_keys($types->taxonomiesOf('posts')))->toBe(['categories', 'tags'])
        ->and($types->taxonomiesOf('pages'))->toBe([])
        ->and($types->get('categories')->isTaxonomy())->toBeTrue()
        ->and($types->get('categories')->supports('body'))->toBeFalse()
        ->and($types->get('posts')->supports('body'))->toBeTrue()
        ->and($types->isActive('tags'))->toBeTrue();

    app(SetContentTypeActive::class)->handle($this->admin, 'posts', false);

    expect($types->isActive('categories'))->toBeFalse()->and($types->isActive('tags'))->toBeFalse();

    // A taxonomy has no switch of its own.
    app(SetContentTypeActive::class)->handle($this->admin, 'posts', true);
    app(SetContentTypeActive::class)->handle($this->admin, 'categories', false);

    expect($types->isActive('categories'))->toBeTrue();
});

test('a category is made on the common form without a body, an image or a status, and is published at once', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');

    Livewire::withQueryParams(['locale' => 'en'])
        ->test('hata::admin.content.create', ['type' => 'categories'])
        ->assertDontSee(__('hata::content.form.publication.title'))
        ->assertDontSee(__('hata::content.form.image.title'))
        ->assertDontSeeHtml('data-test="translation-callout"')
        ->set('form.title', 'Local news')
        ->set('form.slug', 'local')
        ->set('form.parent_id', (string) $news->id)
        ->set('form.status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->where('type', 'categories')->where('path', 'news/local')->sole())
        ->status->toBe(EntryStatus::Published)
        ->isPublic()->toBeTrue();
});

test('a post is filed under categories and tags of its language, and a typed tag is made or found by name', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');
    $existing = term('tags', 'Roofs');
    $ukrainian = term('categories', 'Новини', ['locale' => 'uk', 'slug' => 'novyny', 'path' => 'novyny']);

    postForm()
        ->assertSeeHtml('data-test="terms-categories"')
        ->assertSeeHtml('data-test="terms-tags"')
        ->assertSeeHtml('data-test="term-'.$news->id.'"')
        ->assertDontSeeHtml('data-test="term-'.$ukrainian->id.'"')
        ->set('form.title', 'Open day')
        ->set('form.slug', 'open-day')
        ->set('form.terms', [(string) $news->id])
        ->set('form.newTerms', ['tags' => ['roofs', 'Windows', ' ']])
        ->call('save')
        ->assertHasNoErrors();

    $post = Entry::query()->where('type', 'posts')->sole();
    $windows = Entry::query()->where('type', 'tags')->where('title', 'Windows')->sole();

    expect($post->terms()->pluck('entries.id')->sort()->values()->all())->toBe(collect([$news->id, $existing->id, $windows->id])->sort()->values()->all())
        ->and($windows)->locale->toBe('en')->slug->toBe('windows')->isPublic()->toBeTrue()
        ->and(Entry::query()->where('type', 'tags')->count())->toBe(2);

    // Saved again without the tag: only the category stays.
    postForm($post)->assertSet('form.terms', fn (array $terms): bool => count($terms) === 3)
        ->set('form.terms', [(string) $news->id])
        ->call('save')
        ->assertHasNoErrors();

    expect($post->terms()->pluck('entries.id')->all())->toBe([$news->id]);
});

test('a term of another language or of no taxonomy of the type is a field error', function () {
    $this->actingAs($this->admin);
    $ukrainian = term('categories', 'Новини', ['locale' => 'uk', 'slug' => 'novyny', 'path' => 'novyny']);
    $page = Entry::factory()->create();

    foreach ([$ukrainian, $page] as $wrong) {
        postForm()
            ->set('form.title', 'Open day')
            ->set('form.slug', 'open-day')
            ->set('form.terms', [(string) $wrong->id])
            ->call('save')
            ->assertHasErrors('form.terms');
    }

    expect(Entry::query()->where('type', 'posts')->exists())->toBeFalse();
});

test('without the right to create tags a post is filed under existing terms but no new tag is made', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'posts.view', 'posts.create'])->create());
    $news = term('categories', 'News');

    postForm()
        ->set('form.title', 'Open day')
        ->set('form.slug', 'open-day')
        ->set('form.terms', [(string) $news->id])
        ->set('form.newTerms', ['tags' => ['Windows']])
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->where('type', 'posts')->sole()->terms()->pluck('entries.id')->all())->toBe([$news->id])
        ->and(Entry::query()->where('type', 'tags')->exists())->toBeFalse();
});

test('a translation and a record moved to another language take the terms that have a translation there', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');
    $novyny = Entry::factory()->published()->translationOf($news, 'uk')->create(['title' => 'Новини', 'slug' => 'novyny', 'path' => 'novyny']);
    $untranslated = term('tags', 'Roofs');
    $post = post('Open day');
    $post->terms()->sync([$news->id, $untranslated->id]);

    expect($post->copyFor('uk')['terms'])->toBe([$novyny->id]);

    Livewire::withQueryParams(['from' => (string) $post->id, 'locale' => 'uk'])
        ->test('hata::admin.content.create', ['type' => 'posts'])
        ->assertSet('form.terms', [(string) $novyny->id]);

    postForm($post)
        ->call('changeLocale', 'uk')
        ->assertSet('form.terms', [(string) $novyny->id])
        ->assertSeeHtml('data-test="term-'.$novyny->id.'"')
        ->call('save')
        ->assertHasNoErrors();

    expect($post->fresh()->locale)->toBe('uk')
        ->and($post->terms()->pluck('entries.id')->all())->toBe([$novyny->id]);
});

test('a term that records are filed under keeps its language', function () {
    $news = term('categories', 'News');
    $free = term('categories', 'Free');
    post('Open day')->terms()->sync([$news->id]);
    $data = fn (Entry $term): array => ['title' => $term->title, 'slug' => $term->slug, 'status' => 'published'];

    expect(fn () => app(UpdateEntry::class)->handle($this->admin, $news, $data($news), 'uk'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['locale'])->toBe([__('hata::content.entry.locale_has_records')]));

    app(UpdateEntry::class)->handle($this->admin, $free, $data($free), 'uk');

    expect($free->fresh()->locale)->toBe('uk');
});

test('the list of posts shows their terms and narrows to one, and the list of a taxonomy counts its records', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');
    $roofs = term('tags', 'Roofs');
    $filed = post('Open day');
    $filed->terms()->sync([$news->id, $roofs->id]);
    $other = post('Closed day');
    $row = fn (Entry $entry): string => 'data-test="entry-row-'.$entry->id.'"';

    $both = post('Both');
    $both->terms()->sync([$news->id, $roofs->id]);
    $filed->terms()->sync([$news->id]);

    Livewire::test('hata::admin.content.index', ['type' => 'posts'])
        ->assertSeeHtml(['data-test="list-term-categories"', 'data-test="list-term-tags"'])
        ->assertSeeHtml('data-test="entry-terms-'.$filed->id.'"')
        ->assertSee(['News', 'Roofs'])
        ->set('termFilter.categories', (string) $news->id)
        ->assertSeeHtml([$row($filed), $row($both)])
        ->assertDontSeeHtml($row($other))
        // Both filters together: only what is under the category and the tag.
        ->set('termFilter.tags', (string) $roofs->id)
        ->assertSeeHtml($row($both))
        ->assertDontSeeHtml($row($filed))
        // A tag is no category, and a post is no term: such a choice is dropped.
        ->set('termFilter', ['categories' => (string) $roofs->id, 'tags' => (string) $other->id])
        ->assertSet('termFilter', [])
        ->assertSeeHtml($row($other));

    Livewire::withQueryParams(['term' => ['categories' => (string) $news->id]])
        ->test('hata::admin.content.index', ['type' => 'posts'])
        ->assertSet('termFilter', ['categories' => (string) $news->id])
        ->assertDontSeeHtml($row($other));

    Livewire::test('hata::admin.content.index', ['type' => 'categories'])
        ->assertDontSeeHtml('data-test="list-term-')
        ->assertDontSeeHtml('data-test="tab-drafts"')
        ->assertDontSeeHtml('data-test="entry-state-'.$news->id.'"')
        ->assertSeeHtml('data-test="tab-trash"')
        ->assertSeeHtml('data-test="term-records-'.$news->id.'"');

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->assertDontSeeHtml('data-test="list-term-');
});

test('the sidebar lists the taxonomies after their type', function () {
    $this->actingAs($this->admin);

    $this->get(route('admin.dashboard'))->assertSeeInOrder([
        __('hata::content.types.posts.plural'),
        __('hata::content.types.categories.plural'),
        __('hata::content.types.tags.plural'),
    ]);
});

test('the base of a type lists its public records, newest first, a page at a time', function () {
    post('The oldest', ['published_at' => now()->subDays(40)]);
    post('The newest', ['published_at' => now()->subHour()]);
    post('A draft', ['status' => EntryStatus::Draft, 'published_at' => null]);
    post('Українською', ['locale' => 'uk', 'slug' => 'ukr', 'path' => 'ukr']);

    $page = $this->get('/posts')->assertOk()
        ->assertSeeInOrder(['The newest', 'The oldest'])
        ->assertDontSee('A draft')
        ->assertDontSee('Українською')
        ->assertDontSee('data-test="list-pages"', escape: false)
        ->assertSee('<link rel="alternate" hreflang="uk" href="'.url('/uk/posts').'" />', escape: false);

    $this->get('/uk/posts')->assertOk()->assertSee('Українською')->assertDontSee('The newest');
    $this->get('/categories')->assertNotFound();
    $this->get('/tags')->assertNotFound();

    foreach (range(1, 12) as $number) {
        post('Post '.$number, ['published_at' => now()->subDays($number)]);
    }

    $this->get('/posts')->assertOk()->assertSee('data-test="list-pages"', escape: false)->assertSee('page=2')->assertDontSee('The oldest');
    $this->get('/posts?page=2')->assertOk()->assertSee('The oldest')->assertDontSee('The newest');
});

test('a term has a page with the public records filed under it, and a record links to its terms', function () {
    $news = term('categories', 'News', ['excerpt' => 'What is new']);
    $roofs = term('tags', 'Roofs');
    $filed = post('Open day');
    $filed->terms()->sync([$news->id, $roofs->id]);
    post('A draft', ['status' => EntryStatus::Draft, 'published_at' => null])->terms()->sync([$news->id]);
    post('Unfiled');

    $this->get('/categories/news')->assertOk()
        ->assertSee(['News', 'What is new', 'Open day'])
        ->assertDontSee(['A draft', 'Unfiled']);

    $this->get('/posts/open-day')->assertOk()
        ->assertSee('data-test="entry-terms"', escape: false)
        ->assertSee('href="'.url('/categories/news').'"', escape: false)
        ->assertSee('href="'.url('/tags/roofs').'"', escape: false);

    $this->get('/posts/unfiled')->assertOk()->assertDontSee('data-test="entry-terms"', escape: false);
});

test('a trashed term leaves the record\'s page, and deleting it for good removes its links', function () {
    $news = term('categories', 'News');
    $filed = post('Open day');
    $filed->terms()->sync([$news->id]);

    app(TrashEntry::class)->handle($this->admin, $news);

    $this->get('/posts/open-day')->assertOk()->assertDontSee('data-test="entry-terms"', escape: false);
    $this->get('/categories/news')->assertNotFound();
    expect(DB::table('entry_terms')->count())->toBe(1);

    app(DeleteEntry::class)->handle($this->admin, Entry::onlyTrashed()->findOrFail($news->id));

    expect(DB::table('entry_terms')->count())->toBe(0)->and(Entry::find($filed->id))->not->toBeNull();
});

test('the public lists and term pages are gone while the type is off', function () {
    $news = term('categories', 'News');
    post('Open day')->terms()->sync([$news->id]);

    app(SetContentTypeActive::class)->handle($this->admin, 'posts', false);

    $this->get('/posts')->assertNotFound();
    $this->get('/posts/open-day')->assertNotFound();
    $this->get('/categories/news')->assertNotFound();
});

test('a list page and a term page cost a fixed number of queries', function () {
    $news = term('categories', 'News');

    foreach (range(1, 8) as $number) {
        post('Post '.$number)->terms()->sync([$news->id]);
    }

    $count = function (string $address): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries) {
            $queries += str_contains($query->sql, 'entries') || str_contains($query->sql, 'media') ? 1 : 0;
        });

        $this->get($address)->assertOk();

        return $queries;
    };

    // The list: the records. A term: the term, its translations, the records. None has images here.
    expect($count('/posts'))->toBeLessThanOrEqual(2)
        ->and($count('/categories/news'))->toBeLessThanOrEqual(5);
});

test('a taxonomy can leave its filter out of the list', function () {
    $this->actingAs($this->admin);

    app(ContentTypes::class)->register(new class extends TagsType
    {
        public function key(): string
        {
            return 'series';
        }

        public function base(string $locale): ?string
        {
            return 'series';
        }

        public function hasListFilter(): bool
        {
            return false;
        }
    });

    term('categories', 'News');
    term('tags', 'Roofs');
    $series = term('series', 'Winter');
    $post = post('Open day');
    $post->terms()->sync([$series->id]);

    Livewire::test('hata::admin.content.index', ['type' => 'posts'])
        ->assertSeeHtml(['data-test="list-term-categories"', 'data-test="list-term-tags"'])
        ->assertDontSeeHtml('data-test="list-term-series"')
        // The column still shows its terms; only the filter is gone, also from the address.
        ->assertSee('Winter')
        ->set('termFilter', ['series' => (string) $series->id])
        ->assertSet('termFilter', []);
});

test('a term in the trash keeps its links when the record is saved, and comes back with them', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');
    $other = term('categories', 'Other');
    $filed = post('Open day');
    $filed->terms()->sync([$news->id, $other->id]);

    app(TrashEntry::class)->handle($this->admin, $news);

    postForm($filed)
        ->assertSet('form.terms', [(string) $other->id])
        ->set('form.title', 'Open day, fixed')
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('entry_terms')->where('entry_id', $filed->id)->pluck('term_id')->sort()->values()->all())->toBe([$news->id, $other->id]);

    app(RestoreEntry::class)->handle($this->admin, Entry::onlyTrashed()->findOrFail($news->id));

    expect($filed->terms()->pluck('entries.id')->sort()->values()->all())->toBe([$news->id, $other->id]);
});

test('switching the form to another language and back keeps the terms', function () {
    $this->actingAs($this->admin);
    $news = term('categories', 'News');
    $filed = post('Open day');
    $filed->terms()->sync([$news->id]);

    postForm($filed)
        ->call('changeLocale', 'uk')
        ->assertSet('form.terms', [])
        ->call('changeLocale', 'en')
        ->assertSet('form.terms', [(string) $news->id]);
});

test('after a save the typed tag is an ordinary chosen term of the form', function () {
    $this->actingAs($this->admin);
    $filed = post('Open day');

    $form = postForm($filed)
        ->set('form.newTerms', ['tags' => ['Roofs']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('form.newTerms', []);

    $roofs = Entry::query()->where('type', 'tags')->sole();

    $form->assertSet('form.terms', [(string) $roofs->id])->call('save')->assertHasNoErrors();

    expect(Entry::query()->where('type', 'tags')->count())->toBe(1);
});

test('a term cannot be made a draft, and a typed tag needs the right to create tags', function () {
    $news = term('categories', 'News');

    app(SetEntryStatus::class)->handle($this->admin, $news, EntryStatus::Draft);

    expect($news->fresh()->status)->toBe(EntryStatus::Published);

    // May edit posts, may not create tags.
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'posts.view', 'posts.create', 'posts.edit'])->create());

    postForm()
        ->assertSee(__('hata::content.terms.placeholder'))
        ->assertDontSee(__('hata::content.terms.create_hint'))
        ->set('form.title', 'Open day')
        ->set('form.slug', 'open-day')
        ->set('form.newTerms', ['tags' => ['Windows']])
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->where('type', 'tags')->exists())->toBeFalse();
});

test('a language that is switched off has no list, and its term page opens under the preview strip', function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => false]],
        'content_default_locale' => 'en',
    ]);
    term('categories', 'Новини', ['locale' => 'uk', 'slug' => 'novyny', 'path' => 'novyny']);
    post('Українською', ['locale' => 'uk', 'slug' => 'ukr', 'path' => 'ukr']);

    $this->actingAs($this->admin)->withCookie((string) config('session.cookie'), 'a-session');

    $this->get('/uk/posts')->assertNotFound();
    $this->get('/uk/categories/novyny')->assertOk()->assertSee('data-test="preview-strip"', escape: false);
});

test('each taxonomy has its own limit of terms in the list filter', function () {
    $this->actingAs($this->admin);
    term('categories', 'Zebra');

    foreach (range(1, EntryListPage::TERM_FILTER_OPTIONS + 5) as $number) {
        term('tags', 'Tag '.$number);
    }

    Livewire::test('hata::admin.content.index', ['type' => 'posts'])
        ->assertSeeHtml(['data-test="list-term-categories"', 'data-test="list-term-tags"'])
        ->assertSee('Zebra');
});

test('a term page loads only what a card shows of each record', function () {
    $news = term('categories', 'News');
    post('Open day', ['body' => '<p>A long body</p>'])->terms()->sync([$news->id]);

    $selects = [];
    DB::listen(function ($query) use (&$selects) {
        if (str_contains($query->sql, 'entry_terms') && str_contains($query->sql, 'limit')) {
            $selects[] = $query->sql;
        }
    });

    $this->get('/categories/news')->assertOk()->assertSee('Open day');

    expect($selects)->toHaveCount(1)->and($selects[0])->not->toContain('"entries".*')->not->toContain('body');
});

test('the list of a type that has a base in one language only still opens', function () {
    app(ContentTypes::class)->register(new class extends PostsType
    {
        public function key(): string
        {
            return 'notes';
        }

        public function base(string $locale): ?string
        {
            return $locale === 'en' ? 'notes' : null;
        }
    });

    Entry::factory()->published()->create(['type' => 'notes', 'title' => 'A note', 'slug' => 'a-note', 'path' => 'a-note']);

    $this->get('/notes')->assertOk()->assertSee('A note')->assertDontSee('<link rel="alternate" hreflang="uk"', escape: false);
});
