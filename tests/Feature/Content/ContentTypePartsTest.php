<?php

use App\Models\User;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PagesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Settings\SiteSettings;
use Livewire\Livewire;
use Tests\Fixtures\Content\LeanPostsType;

const PARTS = ['body', 'image', 'publication', 'author', 'excerpt', 'seo'];

beforeEach(function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

});

/**
 * Registers the lean posts type (its permissions are seeded after, so the admin holds them) and signs an admin in.
 *
 * @param  list<string>  $off
 */
function useLeanPosts(array $off = ['author', 'excerpt']): User
{
    app(ContentTypes::class)->register(new LeanPostsType($off));
    seedRolesAndPermissions();

    $admin = User::factory()->withRole('admin')->create();
    test()->actingAs($admin);

    return $admin;
}

function leanPost(array $attributes = []): Entry
{
    return Entry::factory()->published()->create([
        'type' => 'posts', 'title' => 'Hello', 'slug' => 'hello', 'path' => 'hello',
        'excerpt' => 'The excerpt', 'seo_title' => 'The SEO title', 'seo_description' => 'The SEO description',
        'body' => '<p>The body text</p>', ...$attributes,
    ]);
}

test('the kit types keep their parts', function () {
    $pages = new PagesType;
    $posts = new PostsType;
    $categories = new CategoriesType;

    foreach (PARTS as $part) {
        expect($pages->supports($part))->toBeTrue()
            ->and($posts->supports($part))->toBeTrue()
            ->and($categories->supports($part))->toBe(in_array($part, ['excerpt', 'seo']));
    }
});

test('a subclass of the posts type keeps its key and permissions, adds a field and drops author and excerpt', function () {
    useLeanPosts();

    $type = app(ContentTypes::class)->get('posts');

    expect($type)->toBeInstanceOf(LeanPostsType::class)
        ->and($type->permission('edit'))->toBe('posts.edit')
        ->and($type->supports('author'))->toBeFalse()
        ->and($type->supports('excerpt'))->toBeFalse()
        ->and($type->supports('seo'))->toBeTrue();

    $entry = leanPost();

    Livewire::test('hata::admin.content.edit', ['type' => 'posts', 'entry' => $entry])
        ->assertOk()
        ->assertSee('data-test="field-subtitle"', escape: false)
        ->assertDontSee('form.excerpt', escape: false)
        ->assertDontSee(__('hata::content.form.author'))
        ->assertSee('data-test="seo-preview"', escape: false);
});

test('with one part off the form, the list, the public page and the head render', function (string $part) {
    $admin = useLeanPosts([$part]);
    $entry = leanPost();

    Livewire::test('hata::admin.content.create', ['type' => 'posts'])->assertOk();
    Livewire::test('hata::admin.content.edit', ['type' => 'posts', 'entry' => $entry])->assertOk();
    Livewire::test('hata::admin.content.index', ['type' => 'posts'])->assertOk();

    $this->get('/posts/hello')->assertOk();
})->with(PARTS);

test('with one part off a save keeps the stored value of that part', function (string $part) {
    $admin = useLeanPosts([$part]);

    $stored = [
        'body' => '<p>The body text</p>', 'excerpt' => 'The excerpt', 'seo_title' => 'The SEO title',
        'seo_description' => 'The SEO description', 'author_id' => $admin->id,
    ];
    $entry = leanPost($stored);

    // a tampered request that sends empty values for the switched-off part
    $emptied = ['body' => '', 'excerpt' => '', 'seo_title' => '', 'seo_description' => '', 'author_id' => ''];
    $form = Livewire::test('hata::admin.content.edit', ['type' => 'posts', 'entry' => $entry])->set('form.title', 'Renamed');

    foreach ($emptied as $name => $value) {
        $form->set("form.{$name}", $value);
    }

    $form->call('save')->assertHasNoErrors();
    $entry->refresh();

    $kept = match ($part) {
        'body' => ['body'], 'excerpt' => ['excerpt'], 'seo' => ['seo_title', 'seo_description'],
        'author' => ['author_id'], default => [],
    };

    expect($entry->title)->toBe('Renamed');

    foreach ($kept as $column) {
        expect($entry->{$column})->toBe($stored[$column]);
    }

    foreach (array_diff(array_keys($emptied), $kept) as $column) {
        expect($entry->{$column})->toBeNull();
    }
})->with(PARTS);

test('a type without publication always saves published', function () {
    useLeanPosts(['publication']);

    Livewire::withQueryParams(['locale' => 'en'])->test('hata::admin.content.create', ['type' => 'posts'])
        ->set('form.title', 'Fresh')->set('form.slug', 'fresh')->set('form.status', 'draft')
        ->call('save')->assertHasNoErrors();

    expect(Entry::query()->where('path', 'fresh')->firstOrFail()->status)->toBe(EntryStatus::Published);
});

test('the description is the SEO one, else the excerpt, else the body', function (array $off, string $description) {
    useLeanPosts($off);
    leanPost();

    $page = $this->get('/posts/hello')->assertOk()
        ->assertSee('<meta name="description" content="'.$description.'">', escape: false);

    if (in_array('seo', $off)) {
        $page->assertDontSee('The SEO title')->assertDontSee('The SEO description');
    } else {
        $page->assertSee('<title>The SEO title</title>', escape: false);
    }
})->with([
    'all parts' => [[], 'The SEO description'],
    'no seo' => [['seo'], 'The excerpt'],
    'no seo, no excerpt' => [['seo', 'excerpt'], 'The body text'],
]);

test('the cards of a term page show an excerpt only for a type that has one', function (array $off, bool $shown) {
    app(ContentTypes::class)->register(new CategoriesType);
    useLeanPosts($off);
    $term = Entry::factory()->published()->create(['type' => 'categories', 'title' => 'News', 'slug' => 'news', 'path' => 'news']);
    leanPost()->terms()->attach($term);

    $page = $this->get('/categories/news')->assertOk()->assertSee('Hello');

    $shown ? $page->assertSee('The excerpt') : $page->assertDontSee('The excerpt');
})->with([
    'with excerpt' => [[], true],
    'without excerpt' => [['excerpt'], false],
]);
