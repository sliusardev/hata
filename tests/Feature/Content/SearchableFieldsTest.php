<?php

use App\Models\User;
use Hata\Actions\Content\CreateEntry;
use Hata\Actions\Content\LinkTranslations;
use Hata\Actions\Content\UpdateEntry;
use Hata\Enums\SystemRole;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Fields\Number;
use Hata\Support\Content\Fields\RichText;
use Hata\Support\Content\Fields\Text;
use Hata\Support\Content\Fields\Toggle;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ProductsType;

/**
 * A products type whose `sku`, `details`, `weight` and shared `brand` are searched; `secret` is not, and the type
 * also searches the excerpt.
 */
function searchableProductsType(): ProductsType
{
    return new class extends ProductsType
    {
        public function fields(): array
        {
            return [
                Text::make('sku')->searchable(),
                Text::make('secret'),
                Text::make('brand')->shared()->searchable(),
                RichText::make('details')->searchable(),
                Number::make('weight')->searchable(),
                Toggle::make('featured')->searchable(),
            ];
        }

        protected function searchable(): array
        {
            return ['excerpt'];
        }
    };
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function searchableProductData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Чайник',
        'slug' => 'chainyk',
        'parent_id' => null,
        'author_id' => null,
        'image_id' => null,
        'status' => 'draft',
        'published_at' => null,
        'excerpt' => null,
        'seo_title' => null,
        'seo_description' => null,
        'body' => null,
        'fields' => [],
    ], $overrides);
}

/**
 * The titles the list's search scope finds for a term, in every language.
 *
 * @return list<string>
 */
function productsFound(string $term): array
{
    return Entry::query()->where('type', 'products')->search($term)->pluck('title')->sort()->values()->all();
}

beforeEach(function () {
    app(ContentTypes::class)->register(searchableProductsType());
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'uk',
    ]);

    $this->admin = User::factory()->withRole(SystemRole::SuperAdmin->value)->create();
    $this->type = app(ContentTypes::class)->get('products');
});

test('the list search finds a record by a searchable field, whatever the case, and not by another field', function () {
    app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData([
        'fields' => ['sku' => 'АРТ-778', 'secret' => 'hidden-word', 'brand' => 'Bosch', 'weight' => 1250],
    ]));
    app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['title' => 'Праска', 'slug' => 'praska']));

    expect(productsFound('арт-778'))->toBe(['Чайник'])
        ->and(productsFound('BOSCH'))->toBe(['Чайник'])
        ->and(productsFound('1250'))->toBe(['Чайник'])
        ->and(productsFound('чайн'))->toBe(['Чайник'])
        ->and(productsFound('hidden-word'))->toBe([]);
});

test('a rich text field is searched by its text, a toggle is not searched, and the type can add the excerpt', function () {
    app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData([
        'excerpt' => 'Нержавіюча сталь',
        'fields' => ['details' => '<p>Гарантія <strong>два</strong> роки</p>', 'featured' => true],
    ]));

    expect(productsFound('гарантія два роки'))->toBe(['Чайник'])
        ->and(productsFound('strong'))->toBe([])
        ->and(productsFound('нержавіюча'))->toBe(['Чайник'])
        ->and(Entry::query()->value('search_index'))->not->toContain('<p>');
});

test('a changed field is searched by its new value only', function () {
    $entry = app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['fields' => ['sku' => 'OLD-1']]));

    app(UpdateEntry::class)->handle($this->admin, $entry, searchableProductData(['fields' => ['sku' => 'NEW-2']]));

    expect(productsFound('new-2'))->toBe(['Чайник'])->and(productsFound('old-1'))->toBe([]);
});

test('a shared searchable field is searched in every translation, also after one of them changes it', function () {
    $uk = app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['fields' => ['brand' => 'Bosch']]));
    $en = app(CreateEntry::class)->handle($this->admin, $this->type, 'en', searchableProductData(['title' => 'Kettle', 'slug' => 'kettle', 'fields' => ['brand' => 'Bosch']]), $uk);

    expect(productsFound('bosch'))->toBe(['Kettle', 'Чайник']);

    $updatedAt = $uk->fresh()->updated_at;
    $this->travel(5)->minutes();

    app(UpdateEntry::class)->handle($this->admin, $en->fresh(), searchableProductData(['title' => 'Kettle', 'slug' => 'kettle', 'fields' => ['brand' => 'Siemens']]));

    expect(productsFound('siemens'))->toBe(['Kettle', 'Чайник'])
        ->and(productsFound('bosch'))->toBe([])
        ->and($uk->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('a record linked as a translation is searched by the shared field of its new group', function () {
    $uk = app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['fields' => ['brand' => 'Bosch']]));
    $en = app(CreateEntry::class)->handle($this->admin, $this->type, 'en', searchableProductData(['title' => 'Kettle', 'slug' => 'kettle', 'fields' => ['brand' => 'Philips']]));

    app(LinkTranslations::class)->handle($this->admin, $uk, $en);

    expect(productsFound('bosch'))->toBe(['Kettle', 'Чайник'])->and(productsFound('philips'))->toBe([]);
});

test('content:reindex-search rebuilds the index of stored records, trashed ones too, without touching their dates', function () {
    $entry = app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['fields' => ['sku' => 'SKU-5']]));
    $trashed = app(CreateEntry::class)->handle($this->admin, $this->type, 'uk', searchableProductData(['title' => 'Праска', 'slug' => 'praska', 'fields' => ['sku' => 'SKU-6']]));
    $trashed->delete();

    // As stored before the field was made searchable.
    DB::table('entries')->update(['search_index' => 'stale']);
    $updatedAt = $entry->fresh()->updated_at;
    $this->travel(5)->minutes();

    $this->artisan('content:reindex-search')->expectsOutputToContain('2')->assertSuccessful();

    expect(productsFound('sku-5'))->toBe(['Чайник'])
        ->and(Entry::onlyTrashed()->value('search_index'))->toContain('sku-6')
        ->and($entry->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();

    $this->artisan('content:reindex-search')->expectsOutputToContain('0')->assertSuccessful();
});

test('a type without searchable fields is searched by title and slug as before', function () {
    $page = Entry::factory()->published()->create(['type' => 'pages', 'title' => 'Про нас', 'slug' => 'pro-nas', 'path' => 'pro-nas', 'excerpt' => 'Короткий опис']);

    expect($page->fresh()->search_index)->toBe('про нас pro-nas');
});
