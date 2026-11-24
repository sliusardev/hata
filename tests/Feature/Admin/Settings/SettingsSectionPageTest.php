<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\ShopSettingsSection;

beforeEach(function () {
    ShopSettingsSection::register();
    seedRolesAndPermissions();

    $this->shopAdmin = User::factory()->withPermissions(['shop.settings'])->create();
});

it('loads the values in effect', function () {
    config(['shop.currency' => 'EUR', 'shop.token' => 'from-env']);

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->assertSet('values.shop_currency', 'EUR')
        ->assertSet('values.shop_api_token', '')
        ->assertSet('saved.shop_currency', 'EUR');
});

it('save stores only changed fields', function () {
    config(['shop.currency' => 'EUR', 'shop.token' => 'from-env']);

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->set('values.shop_currency', 'USD')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'shop']));

    expect(Setting::query()->pluck('value', 'key')->all())->toBe(['shop_currency' => 'USD'])
        ->and(config('shop.currency'))->toBe('USD')
        ->and(session('toast.text'))->toBe(__('hata::site_settings.messages.saved'));
});

it('save without changes stores nothing', function () {
    config(['shop.currency' => 'EUR']);
    $before = Setting::count();

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'shop']));

    expect(Setting::count())->toBe($before);
});

it('an empty secret keeps the stored one, a typed one is stored encrypted', function () {
    config(['shop.currency' => 'EUR']);
    app(SiteSettings::class)->put(['shop_api_token' => 'old-token']);

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->assertSet('values.shop_api_token', '')
        ->set('values.shop_currency', 'USD')
        ->call('save');

    expect(Crypt::decryptString(Setting::query()->where('key', 'shop_api_token')->value('value')))->toBe('old-token');

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->set('values.shop_api_token', 'new-token')
        ->call('save')
        ->assertHasNoErrors();

    $stored = Setting::query()->where('key', 'shop_api_token')->value('value');

    expect($stored)->not->toBe('new-token')
        ->and(Crypt::decryptString($stored))->toBe('new-token')
        ->and(config('shop.token'))->toBe('new-token');
});

it('validation errors keep everything unsaved', function () {
    config(['shop.currency' => 'EUR']);

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->set('values.shop_currency', 'EURO')
        ->set('values.shop_api_token', 'new-token')
        ->call('save')
        ->assertHasErrors(['values.shop_currency'])
        ->assertNoRedirect();

    expect(Setting::query()->whereIn('key', ['shop_currency', 'shop_api_token'])->count())->toBe(0);
});

it('mount is 403 without the section permission', function () {
    Livewire::actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create())
        ->test(ShopSettingsSection::class)
        ->assertForbidden();
});

it('every action re-checks the section permission', function () {
    $saving = Livewire::actingAs($this->shopAdmin)->test(ShopSettingsSection::class)->set('values.shop_currency', 'USD');
    $typing = Livewire::actingAs($this->shopAdmin)->test(ShopSettingsSection::class);

    $this->shopAdmin->revokePermissionTo('shop.settings');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->shopAdmin->refresh();

    $saving->call('save')->assertForbidden();
    $typing->set('values.shop_currency', 'GBP')->assertForbidden();

    expect(Setting::query()->where('key', 'shop_currency')->exists())->toBeFalse();
});

it('the section root carries the dirty contract', function () {
    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSection::class)
        ->assertSeeHtml('data-settings-section')
        ->assertSeeHtml('isDirty()')
        ->assertSeeHtml('data-test="settings-section-save"');
});
