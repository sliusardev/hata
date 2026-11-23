<?php

use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    app(ContentTypes::class)->register(new ProductsType);
});

test('the public page shows a gallery field as a grid of its images in the picked order', function () {
    $first = Media::factory()->image(800, 600)->create(['alt' => ['en' => 'The roof']]);
    $second = Media::factory()->image(800, 600)->create();
    $document = Media::factory()->create(['kind' => 'document', 'mime' => 'application/pdf']);

    Entry::factory()->published()->create([
        'type' => 'products', 'slug' => 'chair', 'path' => 'chair',
        'fields' => ['photos' => [$second->id, $document->id, $first->id]],
    ]);

    $page = $this->get('/products/chair')->assertOk()
        ->assertSee('data-test="gallery-photos"', escape: false)
        ->assertSee('alt="The roof"', escape: false)
        ->assertDontSee($document->url(), escape: false);

    expect(strpos($page->getContent(), $second->url()))->toBeLessThan(strpos($page->getContent(), $first->url()));
});

test('a record with an empty gallery shows none and makes no media query', function () {
    Entry::factory()->published()->create(['type' => 'products', 'slug' => 'plain', 'path' => 'plain']);

    $mediaQueries = 0;
    DB::listen(function ($query) use (&$mediaQueries) {
        $mediaQueries += preg_match('/from [`"]media[`"]/', $query->sql);
    });

    $this->get('/products/plain')->assertOk()->assertDontSee('data-test="gallery-', escape: false);

    expect($mediaQueries)->toBe(0);
});
