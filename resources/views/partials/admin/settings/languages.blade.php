{{--
    Site settings → Languages: the content languages of the site (ContentLocales) and, below, the default
    interface language. Changes to the list apply at once through the page's actions; the field is saved with
    the tab's Save button. The dialogs are in languages-modals, outside the settings form.
--}}
@php
    $siteLanguages = $this->siteLanguages;
    $defaultLanguage = $siteLanguages[0]['language'];
@endphp

<div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900" data-test="site-languages">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-white/10">
        <div class="min-w-0 flex-1">
            <flux:heading level="2">{{ __('site_settings.languages.list_heading') }}</flux:heading>
            <flux:text size="sm" class="mt-1 max-w-prose">{{ __('site_settings.languages.list_description') }}</flux:text>
        </div>

        <flux:modal.trigger name="add-language">
            <flux:button size="sm" variant="primary" icon="plus" data-test="add-language-button">{{ __('site_settings.languages.add') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <ul class="divide-y divide-zinc-200 dark:divide-white/10">
        @foreach ($siteLanguages as $row)
            @php($language = $row['language'])
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5" wire:key="site-language-{{ $language->code }}" data-test="site-language-{{ $language->code }}">
                <div class="min-w-0 flex-1 basis-48">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="truncate font-medium text-zinc-800 dark:text-white" lang="{{ $language->hreflang() }}" dir="{{ $language->direction() }}">{{ $language->native }}</span>
                        @if ($row['default'])
                            <flux:badge size="sm" color="amber">{{ __('site_settings.languages.badges.default') }}</flux:badge>
                        @endif
                        @if ($language->hasInterface())
                            <flux:badge size="sm" title="{{ __('site_settings.languages.badges.interface_hint') }}">{{ __('site_settings.languages.badges.interface') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text size="sm">{{ $language->name }}</flux:text>
                </div>

                <div class="w-40 font-mono text-xs text-zinc-500 max-sm:hidden dark:text-zinc-400">
                    {{ $row['default'] ? '/' : '/'.$language->code.'/…' }}
                </div>

                <div class="flex w-32 items-center justify-end gap-2">
                    @if ($row['default'])
                        <flux:text size="sm">{{ __('site_settings.languages.always_on') }}</flux:text>
                    @else
                        <flux:text size="sm">{{ $row['active'] ? __('site_settings.languages.on') : __('site_settings.languages.off') }}</flux:text>
                        <button
                            type="button"
                            role="switch"
                            aria-checked="{{ $row['active'] ? 'true' : 'false' }}"
                            aria-label="{{ __($row['active'] ? 'site_settings.languages.turn_off' : 'site_settings.languages.turn_on', ['language' => $language->name]) }}"
                            wire:click="toggleLanguage('{{ $language->code }}')"
                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent {{ $row['active'] ? 'bg-accent' : 'bg-zinc-300 dark:bg-zinc-600' }}"
                            data-test="language-switch-{{ $language->code }}"
                        >
                            <span class="inline-block size-4 rounded-full bg-white shadow-sm transition-transform {{ $row['active'] ? 'translate-x-4.5 rtl:-translate-x-4.5' : 'translate-x-0.5 rtl:-translate-x-0.5' }}"></span>
                        </button>
                    @endif
                </div>

                <div class="flex w-8 justify-end">
                    @unless ($row['default'])
                        <x-admin.row-actions data-test="language-actions-{{ $language->code }}">
                            @if ($row['active'])
                                <flux:menu.item icon="star" wire:click="confirmLanguage('default', '{{ $language->code }}')">{{ __('site_settings.languages.make_default') }}</flux:menu.item>
                            @endif
                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmLanguage('remove', '{{ $language->code }}')">{{ __('site_settings.languages.remove') }}</flux:menu.item>
                        </x-admin.row-actions>
                    @endunless
                </div>
            </li>
        @endforeach
    </ul>
</div>

<div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-zinc-900">
    <div>
        <flux:heading level="2">{{ __('site_settings.languages.interface_heading') }}</flux:heading>
        <flux:text size="sm" class="mt-1 max-w-prose">{{ __('site_settings.languages.interface_description') }}</flux:text>
    </div>

    <div class="max-w-sm">
        <flux:select wire:model="form.default_locale" :label="__('site_settings.languages.default_locale')">
            @foreach (config('app.available_locales') as $code => $languageName)
                <flux:select.option :value="$code" wire:key="locale-{{ $code }}">{{ $languageName }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @unless ($defaultLanguage->hasInterface())
        <flux:text size="sm" variant="subtle">{{ __('site_settings.languages.interface_missing', ['language' => $defaultLanguage->name]) }}</flux:text>
    @endunless
</div>
