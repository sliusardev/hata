<?php

use App\Models\User;
use Hata\Models\Setting;
use Hata\Support\Admin\SettingsSections;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Fixtures\ShopSettingsSection;

class ShopSettingsSectionWithoutCurrencyRule extends ShopSettingsSection
{
    protected function rules(): array
    {
        return ['values.shop_api_token' => ['nullable', 'string', 'max:255']];
    }
}

class ShopStoredOnlySection extends ShopSettingsSection
{
    protected function fields(): array
    {
        return ['shop_flags', 'shop_note', 'shop_hidden'];
    }

    protected function secretFields(): array
    {
        return ['shop_hidden'];
    }

    protected function rules(): array
    {
        return [
            'values.shop_flags' => ['nullable', 'array'],
            'values.shop_note' => ['nullable', 'string'],
            'values.shop_hidden' => ['nullable', 'string'],
        ];
    }

    public function render(): string
    {
        return '<div data-settings-section></div>';
    }
}

class ShopOverridingHydrate extends ShopSettingsSection
{
    public function hydrate(): void {}
}

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

it('never stores a field without a validation rule from the browser', function () {
    config(['shop.currency' => 'EUR']);

    expect(fn () => Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSectionWithoutCurrencyRule::class)
        ->set('values.shop_currency', ['injected' => str_repeat('x', 5000)])
        ->call('save'))->toThrow(LogicException::class, 'shop_currency');

    app()->detectEnvironment(fn (): string => 'production');
    Log::spy();

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopSettingsSectionWithoutCurrencyRule::class)
        ->set('values.shop_currency', 'USD')
        ->set('values.shop_api_token', 'new-token')
        ->call('save')
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'shop']));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'shop_currency'));

    expect(Setting::query()->where('key', 'shop_currency')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'shop_api_token')->exists())->toBeTrue();
});

it('mount is 403 for a section that is not registered', function () {
    app(SettingsSections::class)->remove('shop');

    Livewire::actingAs($this->shopAdmin)->test(ShopSettingsSection::class)->assertForbidden();
});

it('stored-only encrypted and array keys round-trip through put() and value() and load into a section', function () {
    $settings = app(SiteSettings::class);
    $settings->define('shop_flags', null, 'array')->define('shop_note', null, encrypted: true)->define('shop_hidden', null, encrypted: true);

    $settings->put(['shop_flags' => ['a', 'b'], 'shop_note' => 'plain note', 'shop_hidden' => 'top secret']);

    expect($settings->value('shop_flags'))->toBe(['a', 'b'])
        ->and($settings->value('shop_note'))->toBe('plain note')
        ->and(Setting::query()->where('key', 'shop_note')->value('value'))->not->toBe('plain note');

    $settings->putStoredOnly(['shop_note' => 'second note', 'shop_flags' => ['c']]);

    expect($settings->value('shop_note'))->toBe('second note')
        ->and($settings->value('shop_flags'))->toBe(['c'])
        ->and(Setting::query()->where('key', 'shop_note')->value('value'))->not->toBe('second note');

    Livewire::actingAs($this->shopAdmin)
        ->test(ShopStoredOnlySection::class)
        ->assertSet('values.shop_flags', ['c'])
        ->assertSet('values.shop_note', 'second note')
        ->assertSet('values.shop_hidden', '')
        ->assertDontSee('top secret');
});

it('a subclass overriding hydrate() without calling the parent is still 403 after the permission is revoked', function () {
    $page = Livewire::actingAs($this->shopAdmin)->test(ShopOverridingHydrate::class);

    $this->shopAdmin->revokePermissionTo('shop.settings');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->shopAdmin->refresh();

    $page->set('values.shop_currency', 'GBP')->assertForbidden();
});
