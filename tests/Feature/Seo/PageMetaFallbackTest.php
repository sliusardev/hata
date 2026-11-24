<?php

use Hata\Models\Media;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Seo\PageMeta;
use Illuminate\Support\Facades\DB;

function svgMedia(): Media
{
    return Media::factory()->image()->create(['name' => 'logo.svg', 'path' => 'media/x.svg', 'mime' => 'image/svg+xml', 'uploaded_extension' => 'svg']);
}

it('falls back to the site name for the title', function () {
    config(['app.name' => 'Acme']);

    expect(app(PageMeta::class)->title())->toBe('Acme')
        ->and(app(PageMeta::class)->setTitle('Hi')->title())->toBe('Hi');
});

it('falls back to the site description of the current language', function () {
    $code = app(ContentLocales::class)->current()->code;
    config(['hata.seo.description' => [$code => '<p>Site  text</p>']]);

    expect(app(PageMeta::class)->description())->toBe('Site text');

    config(['hata.seo.description' => ['zz' => 'Other']]);

    expect((new PageMeta)->description())->toBeNull();
});

it('has no canonical for a noindex page', function () {
    $meta = (new PageMeta)->setCanonical('https://x.test/a');

    expect($meta->canonical())->toBe('https://x.test/a')
        ->and($meta->setNoindex()->canonical())->toBeNull();
});

it('uses the own image, skipping SVG and documents for the site image', function () {
    $site = Media::factory()->image()->create();
    config(['hata.seo.image' => $site->id]);
    $own = Media::factory()->image()->create();

    expect((new PageMeta)->setImage($own)->image()?->is($own))->toBeTrue()
        ->and((new PageMeta)->setImage(svgMedia())->image()?->is($site))->toBeTrue()
        ->and((new PageMeta)->setImage(Media::factory()->document()->create())->image()?->is($site))->toBeTrue();
});

it('has no image when the site image is gone', function () {
    config(['hata.seo.image' => 999999]);

    expect((new PageMeta)->image())->toBeNull();
});

it('reads the site image with one query', function () {
    $site = Media::factory()->image()->create();
    config(['hata.seo.image' => $site->id]);
    $meta = new PageMeta;

    DB::enableQueryLog();
    $meta->image();
    $meta->image();

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('is a fresh instance per scope', function () {
    $first = app(PageMeta::class);
    expect(app(PageMeta::class))->toBe($first);

    app()->forgetScopedInstances();

    expect(app(PageMeta::class))->not->toBe($first);
});
