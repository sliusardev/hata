<?php

namespace Tests\Fixtures;

use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Support\Admin\SettingsSection;
use Hata\Support\Admin\SettingsSections;
use Hata\Support\Permissions\PermissionRegistry;
use Hata\Support\Settings\SiteSettings;
use Livewire\Livewire;

/**
 * Test-only settings section, as a site or module would add one: a plain field (`shop_currency` over
 * `shop.currency`) and an encrypted secret (`shop_api_token` over `shop.token`), behind its own permission
 * `shop.settings`.
 */
class ShopSettingsSection extends SettingsSectionPage
{
    /**
     * What a module does in its provider: define the keys, register the permission, the component and the section.
     * Permissions still need `permissions:sync` (or `seedRolesAndPermissions()`) afterwards.
     */
    public static function register(): void
    {
        app(SiteSettings::class)
            ->define('shop_currency', 'shop.currency')
            ->define('shop_api_token', 'shop.token', encrypted: true);

        app(PermissionRegistry::class)->register('shop', ['settings']);

        Livewire::component('shop-settings', self::class);

        app(SettingsSections::class)->add(new SettingsSection('shop', 'Shop', 'shopping-cart', 'shop-settings', 'shop.settings', 80));
    }

    public static function key(): string
    {
        return 'shop';
    }

    protected function fields(): array
    {
        return ['shop_currency', 'shop_api_token'];
    }

    protected function secretFields(): array
    {
        return ['shop_api_token'];
    }

    protected function rules(): array
    {
        return [
            'values.shop_currency' => ['required', 'string', 'size:3'],
            'values.shop_api_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function render(): string
    {
        return <<<'BLADE'
            <x-hata::admin.settings-section heading="Shop" description="Prices and the payment provider." :secrets="['shop_api_token']">
                <flux:card class="space-y-6">
                    <flux:heading level="2">Prices</flux:heading>
                    <flux:input wire:model="values.shop_currency" label="Currency" data-test="shop-currency" />
                </flux:card>

                <flux:card class="space-y-6">
                    <flux:heading level="2">Payment provider</flux:heading>
                    <flux:input type="password" wire:model="values.shop_api_token" label="API token" viewable data-test="shop-api-token" />
                </flux:card>
            </x-hata::admin.settings-section>
            BLADE;
    }
}
