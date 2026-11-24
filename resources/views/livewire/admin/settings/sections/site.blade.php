<?php

use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Livewire\Attributes\Computed;

new class extends SettingsSectionPage {
    public static function key(): string
    {
        return 'site';
    }

    protected function fields(): array
    {
        return ['site_name', 'timezone'];
    }

    protected function rules(): array
    {
        return [
            'values.site_name' => ['required', 'string', 'max:255'],
            'values.timezone' => ['required', 'timezone:all'],
        ];
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}; ?>

<x-hata::admin.settings-section>
    <flux:input wire:model="values.site_name" :label="__('hata::site_settings.site.site_name')" :description="__('hata::site_settings.site.site_name_description')" required />

    <div class="grid gap-6 sm:grid-cols-2">
        <flux:select wire:model="values.timezone" :label="__('hata::site_settings.site.timezone')" :description="__('hata::site_settings.site.timezone_description')">
            @foreach ($this->timezones as $timezone)
                <flux:select.option :value="$timezone" wire:key="tz-{{ $timezone }}">{{ str_replace('_', ' ', $timezone) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
</x-hata::admin.settings-section>
