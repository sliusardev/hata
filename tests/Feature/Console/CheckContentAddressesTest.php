<?php

use App\Models\Entry;
use App\Support\Content\ContentTypes;
use App\Support\Settings\SiteSettings;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true]],
        'content_default_locale' => 'en',
    ]);
});

test('it passes when no page sits at the base path of another type', function () {
    app(ContentTypes::class)->register(new ProductsType);
    Entry::factory()->create(['slug' => 'about', 'path' => 'about']);
    Entry::factory()->create(['slug' => 'products-list', 'path' => 'products-list']);
    Entry::factory()->create(['type' => 'products', 'slug' => 'chair', 'path' => 'chair']);

    $this->artisan('content:check')->assertSuccessful();
});

test('it lists the pages at and under the base path of another type, in every language and in the trash', function () {
    app(ContentTypes::class)->register(new ProductsType);
    $page = Entry::factory()->create(['slug' => 'products', 'path' => 'products']);
    Entry::factory()->childOf($page)->create(['slug' => 'sale', 'path' => 'products/sale']);
    Entry::factory()->inLocale('uk')->create(['slug' => 'products', 'path' => 'products', 'deleted_at' => now()]);

    $this->artisan('content:check')
        ->expectsOutputToContain('3 record(s) cannot be reached')
        ->expectsOutputToContain('/products/sale')
        ->expectsOutputToContain('/products (in the trash)')
        ->assertFailed();
});

test('it fails for a type whose base is not one slug', function () {
    app(ContentTypes::class)->register(new class extends ProductsType
    {
        public function key(): string
        {
            return 'broken';
        }

        public function base(string $locale): ?string
        {
            return 'shop/items';
        }
    });

    $this->artisan('content:check')->expectsOutputToContain('is not a slug')->assertFailed();
});
