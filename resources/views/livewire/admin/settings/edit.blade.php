<?php

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\SettingsSection;
use Hata\Support\Admin\SettingsSections;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The site settings page: the list of the user's sections and the open one. Each section is its own component
 * (SettingsSections); switching is navigation to `?tab=`, so the one browser guard below covers it as it covers
 * leaving the page.
 */
new #[Layout('hata::layouts.app')] #[Title('hata::site_settings.title')] class extends Component {
    /**
     * The open section's key (an old tab key is mapped on mount).
     */
    #[Url]
    public string $tab = '';

    public function mount(): void
    {
        $section = $this->user() === null ? null : app(SettingsSections::class)->open($this->user(), $this->tab);

        abort_if($section === null, 403);

        $this->tab = $section->key;
    }

    /**
     * The sections this user may open, in order.
     *
     * @return list<SettingsSection>
     */
    #[Computed]
    public function sections(): array
    {
        return $this->user() === null ? [] : app(SettingsSections::class)->for($this->user());
    }

    private function user(): ?AdminUser
    {
        $user = Auth::user();

        return $user instanceof AdminUser ? $user : null;
    }
}; ?>

@php($open = collect($this->sections)->firstWhere('key', $tab))

<section
    class="w-full max-w-5xl space-y-6"
    x-data="{
        pendingUrl: null,
        leaving: false,
        isDirty() {
            const section = document.querySelector('[data-settings-section]');

            return section !== null && Alpine.$data(section).isDirty();
        },
        leave() {
            this.leaving = true;
            $flux.modal('leave-site-settings').close();
            Livewire.navigate(this.pendingUrl);
        },
    }"
    x-on:livewire:navigate.document="if (! leaving && ! $event.detail.history && isDirty()) { $event.preventDefault(); pendingUrl = $event.detail.url.toString(); $flux.modal('leave-site-settings').show(); }"
    x-on:beforeunload.window="if (! leaving && isDirty()) { $event.preventDefault(); $event.returnValue = ''; }"
>
    <div>
        <flux:heading size="xl" level="1">{{ __('hata::site_settings.title') }}</flux:heading>
        <flux:subheading>{{ __('hata::site_settings.description') }}</flux:subheading>
    </div>

    {{-- The sections: a list at the side from a tablet up, a row that scrolls on a phone. --}}
    <flux:navbar scrollable class="-mx-3 border-b border-zinc-200 px-0 md:hidden dark:border-zinc-700" data-test="site-settings-tabs-row">
        @foreach ($this->sections as $section)
            <flux:navbar.item :icon="$section->icon" :href="route('admin.settings.edit', ['tab' => $section->key])" :current="$tab === $section->key" wire:navigate wire:key="tab-row-{{ $section->key }}">
                {{ __($section->label) }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>

    <div class="flex items-start gap-8 lg:gap-10">
        <div class="sticky top-4 w-52 shrink-0 max-md:hidden">
            <flux:navlist aria-label="{{ __('hata::site_settings.title') }}" data-test="site-settings-tabs">
                @foreach ($this->sections as $section)
                    <flux:navlist.item :icon="$section->icon" :href="route('admin.settings.edit', ['tab' => $section->key])" :current="$tab === $section->key" wire:navigate wire:key="tab-{{ $section->key }}" data-test="site-settings-tab-{{ $section->key }}">
                        {{ __($section->label) }}
                    </flux:navlist.item>
                @endforeach
            </flux:navlist>
        </div>

        <div class="min-w-0 max-w-3xl flex-1">
            @if ($open !== null)
                <livewire:dynamic-component :is="$open->component" :key="'settings-section-'.$open->key" />
            @endif
        </div>
    </div>

    <flux:modal name="leave-site-settings" class="max-w-md md:min-w-md">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('hata::site_settings.unsaved.heading') }}</flux:heading>
                <flux:text>{{ __('hata::site_settings.unsaved.leave_body') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('hata::common.actions.cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" x-on:click="leave()" data-test="leave-without-saving-button">
                    {{ __('hata::site_settings.unsaved.leave') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
