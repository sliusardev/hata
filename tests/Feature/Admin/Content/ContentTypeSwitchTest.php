<?php

use App\Models\User;
use Hata\Actions\Settings\SetContentTypeActive;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(ContentTypes::class)->register(new ProductsType);
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);
});

function typesAdmin(): User
{
    return User::factory()->withRole('admin')->create();
}

test('a type is switched off and on again, and its records stay', function () {
    $types = app(ContentTypes::class);
    $product = Entry::factory()->published()->create(['type' => 'products', 'slug' => 'chair', 'path' => 'chair']);

    expect($types->isActive('products'))->toBeTrue()->and(array_keys($types->active()))->toBe(['pages', 'products']);

    app(SetContentTypeActive::class)->handle(typesAdmin(), 'products', false);

    expect($types->isActive('products'))->toBeFalse()
        ->and(array_keys($types->active()))->toBe(['pages'])
        ->and(array_keys($types->all()))->toBe(['pages', 'products'])
        ->and(Entry::find($product->id))->not->toBeNull();

    app(SetContentTypeActive::class)->handle(typesAdmin(), 'products', true);

    expect($types->isActive('products'))->toBeTrue()->and($types->disabledKeys())->toBe([]);
});

test('the pages type, an unknown type and an actor without settings.edit are refused', function () {
    $switch = app(SetContentTypeActive::class);

    expect(fn () => $switch->handle(typesAdmin(), 'pages', false))->toThrow(ProtectedActionException::class)
        ->and(fn () => $switch->handle(typesAdmin(), 'recipes', false))->toThrow(ProtectedActionException::class)
        ->and(fn () => $switch->handle(User::factory()->withRole('editor')->create(), 'products', false))->toThrow(AuthorizationException::class)
        ->and(app(ContentTypes::class)->isActive('pages'))->toBeTrue()
        ->and(app(ContentTypes::class)->isActive('products'))->toBeTrue();
});

test('a type that is off has no admin pages, no menu item and no public address', function () {
    $this->actingAs($admin = typesAdmin());
    $product = Entry::factory()->published()->create(['type' => 'products', 'slug' => 'chair', 'path' => 'chair']);

    $this->get('/products/chair')->assertOk();
    $this->get(route('admin.dashboard'))->assertSee('Products');

    app(SetContentTypeActive::class)->handle($admin, 'products', false);

    $this->get('/products/chair')->assertNotFound();
    $this->get(route('admin.dashboard'))->assertDontSee('Products');
    Livewire::test('hata::admin.content.index', ['type' => 'products'])->assertNotFound();
    Livewire::test('hata::admin.content.create', ['type' => 'products'])->assertNotFound();
    Livewire::test('hata::admin.content.edit', ['type' => 'products', 'entry' => $product])->assertNotFound();
    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->assertOk();
});

test('the base of a type that is off stays reserved, and the media library still names its records without a link', function () {
    $this->actingAs($admin = typesAdmin());
    $file = Media::factory()->create();
    $product = Entry::factory()->create(['type' => 'products', 'title' => 'Chair', 'image_id' => $file->id]);
    MediaUsage::sync($product, 'image', [$file->id]);

    app(SetContentTypeActive::class)->handle($admin, 'products', false);

    Livewire::withQueryParams(['locale' => 'en'])
        ->test('hata::admin.content.create', ['type' => 'pages'])
        ->set('form.title', 'Products')
        ->set('form.slug', 'products')
        ->call('save')
        ->assertHasErrors('form.slug');

    expect($product->mediaUsageLabel('image'))->toContain('Chair')
        ->and($product->mediaUsageUrl('image', $admin))->toBeNull();
});

test('the settings tab lists the types and switches them, asking before switching one off', function () {
    $this->actingAs(typesAdmin());
    Entry::factory()->count(2)->create(['type' => 'products']);

    $page = Livewire::withQueryParams(['tab' => 'content'])
        ->test('hata::admin.settings.edit')
        ->assertSeeHtml('data-test="content-type-pages" data-active="true"')
        ->assertSeeHtml('data-test="content-type-products" data-active="true"')
        ->assertDontSeeHtml('data-test="content-type-switch-pages"')
        ->assertDontSeeHtml('data-test="save-site-settings-button"')
        ->assertSee(trans_choice('site_settings.content.records', 2))
        ->call('toggleContentType', 'products')
        ->assertSet('showContentTypeModal', true);

    expect(app(ContentTypes::class)->isActive('products'))->toBeTrue();

    $page->call('turnOffContentType')->assertRedirect(route('admin.settings.edit', ['tab' => 'content']));

    expect(app(ContentTypes::class)->isActive('products'))->toBeFalse()
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('site_settings.content.messages.turned_off', ['type' => 'Products']));

    Livewire::withQueryParams(['tab' => 'content'])
        ->test('hata::admin.settings.edit')
        ->assertSeeHtml('data-test="content-type-products" data-active="false"')
        ->call('toggleContentType', 'products')
        ->assertSet('showContentTypeModal', false)
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'content']));

    expect(app(ContentTypes::class)->isActive('products'))->toBeTrue();
});

test('switching a type needs settings.edit on the page too', function () {
    $this->actingAs(typesAdmin());
    $page = Livewire::withQueryParams(['tab' => 'content'])->test('hata::admin.settings.edit');

    $this->actingAs(User::factory()->withRole('editor')->create());

    $page->call('toggleContentType', 'products')->assertForbidden();
});
