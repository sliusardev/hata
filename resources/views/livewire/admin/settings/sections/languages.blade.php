<?php

use Flux\Flux;
use Hata\Actions\Settings\AddContentLocale;
use Hata\Actions\Settings\RemoveContentLocale;
use Hata\Actions\Settings\SetContentLocaleActive;
use Hata\Actions\Settings\SetDefaultContentLocale;
use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Exceptions\ContentLocaleException;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\Language;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

new class extends SettingsSectionPage {
    /**
     * What the open confirmation is about: "off", "default" or "remove", and the language.
     */
    #[Locked]
    public string $languageAction = '';

    #[Locked]
    public string $languageCode = '';

    public bool $showLanguageModal = false;

    /**
     * "Use it for the admin and sign-in pages too" in the make-default confirmation.
     */
    public bool $languageAlsoInterface = true;

    public static function key(): string
    {
        return 'languages';
    }

    protected function fields(): array
    {
        return ['default_locale'];
    }

    protected function rules(): array
    {
        return [
            'values.default_locale' => ['required', Rule::in(array_keys(config('hata.admin.interface_locales')))],
        ];
    }

    /**
     * The site's interface default, not the language of this request.
     */
    protected function load(): array
    {
        return ['default_locale' => app(ContentLocales::class)->interfaceDefault()];
    }

    protected function persist(array $changed): void
    {
        app(UpdateSiteSettings::class)->handle(Auth::user(), $changed);
    }

    /**
     * The site's content languages, the default first.
     *
     * @return list<array{language: Language, active: bool, default: bool}>
     */
    #[Computed]
    public function siteLanguages(): array
    {
        $locales = app(ContentLocales::class);

        return array_map(fn (array $entry): array => [
            'language' => $locales->language($entry['code']),
            'active' => $entry['active'],
            'default' => $entry['code'] === $locales->defaultCode(),
        ], $locales->entries());
    }

    /**
     * The catalogue languages that are not on the site yet, by their English name.
     *
     * @return list<Language>
     */
    #[Computed]
    public function catalogLanguages(): array
    {
        $locales = app(ContentLocales::class);

        return array_values(array_filter(app(LanguageCatalog::class)->all(), fn (Language $language): bool => ! $locales->has($language->code)));
    }

    public function addLanguage(AddContentLocale $addContentLocale, string $code): void
    {
        $this->changeLanguages(
            fn () => $addContentLocale->handle(Auth::user(), $code),
            'hata::site_settings.languages.messages.added',
            $code,
        );
    }

    /**
     * Turn a language on at once; turning it off asks first, because its pages stop answering.
     */
    public function toggleLanguage(SetContentLocaleActive $setContentLocaleActive, string $code): void
    {
        if (app(ContentLocales::class)->isActive($code)) {
            $this->confirmLanguage('off', $code);

            return;
        }

        $this->changeLanguages(
            fn () => $setContentLocaleActive->handle(Auth::user(), $code, true),
            'hata::site_settings.languages.messages.turned_on',
            $code,
        );
    }

    public function confirmLanguage(string $action, string $code): void
    {
        $this->authorizeSection();

        if (! in_array($action, ['off', 'default', 'remove'], true) || ! app(ContentLocales::class)->has($code)) {
            return;
        }

        $this->languageAction = $action;
        $this->languageCode = $code;
        $this->languageAlsoInterface = true;
        $this->showLanguageModal = true;
    }

    public function runLanguageAction(
        SetContentLocaleActive $setContentLocaleActive,
        SetDefaultContentLocale $setDefaultContentLocale,
        RemoveContentLocale $removeContentLocale,
    ): void {
        $action = $this->languageAction;
        $code = $this->languageCode;
        $this->showLanguageModal = false;
        $this->languageAction = '';
        $this->languageCode = '';

        if ($code === '') {
            return;
        }

        $actor = Auth::user();

        match ($action) {
            'off' => $this->changeLanguages(fn () => $setContentLocaleActive->handle($actor, $code, false), 'hata::site_settings.languages.messages.turned_off', $code),
            'default' => $this->changeLanguages(fn () => $setDefaultContentLocale->handle($actor, $code, $this->languageAlsoInterface), 'hata::site_settings.languages.messages.default_changed', $code),
            'remove' => $this->changeLanguages(fn () => $removeContentLocale->handle($actor, $code), 'hata::site_settings.languages.messages.removed', $code),
            default => null,
        };
    }

    /**
     * Run a change to the site's languages and reload the tab, so the whole page (and its language, when the
     * interface default changed) is rendered from the new settings. A refusal is a danger toast.
     */
    private function changeLanguages(\Closure $change, string $message, string $code): void
    {
        $this->authorizeSection();

        $name = app(ContentLocales::class)->language($code)->name;

        try {
            $change();
        } catch (ContentLocaleException $exception) {
            unset($this->siteLanguages, $this->catalogLanguages);
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__($message, ['language' => $name]));

        // The tab reloads from the stored settings; an unsaved choice in its field is dropped here, so the
        // leave-page warning does not fire on the redirect.
        $this->values = $this->saved;

        $this->redirectRoute('admin.settings.edit', ['tab' => static::key()]);
    }
}; ?>

<div>
    <x-hata::admin.settings-section>
        @php
            $siteLanguages = $this->siteLanguages;
            $defaultLanguage = $siteLanguages[0]['language'];
        @endphp

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900" data-test="site-languages">
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-white/10">
                <div class="min-w-0 flex-1">
                    <flux:heading level="2">{{ __('hata::site_settings.languages.list_heading') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 max-w-prose">{{ __('hata::site_settings.languages.list_description') }}</flux:text>
                </div>

                <flux:modal.trigger name="add-language">
                    <flux:button size="sm" variant="primary" icon="plus" data-test="add-language-button">{{ __('hata::site_settings.languages.add') }}</flux:button>
                </flux:modal.trigger>
            </div>

            <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                @foreach ($siteLanguages as $row)
                    @php $language = $row['language']; @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5" wire:key="site-language-{{ $language->code }}" data-test="site-language-{{ $language->code }}">
                        <div class="min-w-0 flex-1 basis-48">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="truncate font-medium text-zinc-800 dark:text-white" lang="{{ $language->hreflang() }}" dir="{{ $language->direction() }}">{{ $language->native }}</span>
                                @if ($row['default'])
                                    <flux:badge size="sm" color="amber">{{ __('hata::site_settings.languages.badges.default') }}</flux:badge>
                                @endif
                                @if ($language->hasInterface())
                                    <flux:badge size="sm" title="{{ __('hata::site_settings.languages.badges.interface_hint') }}">{{ __('hata::site_settings.languages.badges.interface') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="sm">{{ $language->name }}</flux:text>
                        </div>

                        <div class="w-40 font-mono text-xs text-zinc-500 max-sm:hidden dark:text-zinc-400">
                            {{ $row['default'] ? '/' : '/'.$language->code.'/…' }}
                        </div>

                        <div class="flex w-32 items-center justify-end gap-2">
                            @if ($row['default'])
                                <flux:text size="sm">{{ __('hata::site_settings.languages.always_on') }}</flux:text>
                            @else
                                <flux:text size="sm">{{ $row['active'] ? __('hata::site_settings.languages.on') : __('hata::site_settings.languages.off') }}</flux:text>
                                <button
                                    type="button"
                                    role="switch"
                                    aria-checked="{{ $row['active'] ? 'true' : 'false' }}"
                                    aria-label="{{ __($row['active'] ? 'hata::site_settings.languages.turn_off' : 'hata::site_settings.languages.turn_on', ['language' => $language->name]) }}"
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
                                <x-hata::admin.row-actions data-test="language-actions-{{ $language->code }}">
                                    @if ($row['active'])
                                        <flux:menu.item icon="star" wire:click="confirmLanguage('default', '{{ $language->code }}')">{{ __('hata::site_settings.languages.make_default') }}</flux:menu.item>
                                    @endif
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmLanguage('remove', '{{ $language->code }}')">{{ __('hata::site_settings.languages.remove') }}</flux:menu.item>
                                </x-hata::admin.row-actions>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-zinc-900">
            <div>
                <flux:heading level="2">{{ __('hata::site_settings.languages.interface_heading') }}</flux:heading>
                <flux:text size="sm" class="mt-1 max-w-prose">{{ __('hata::site_settings.languages.interface_description') }}</flux:text>
            </div>

            <div class="max-w-sm">
                <flux:select wire:model="values.default_locale" :label="__('hata::site_settings.languages.default_locale')">
                    @foreach (config('hata.admin.interface_locales') as $code => $languageName)
                        <flux:select.option :value="$code" wire:key="locale-{{ $code }}">{{ $languageName }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            @unless ($defaultLanguage->hasInterface())
                <flux:text size="sm" variant="subtle">{{ __('hata::site_settings.languages.interface_missing', ['language' => $defaultLanguage->name]) }}</flux:text>
            @endunless
        </div>
    </x-hata::admin.settings-section>

    {{-- The dialogs sit outside the form, so Enter in the search never submits it. --}}
    @php
        $confirmLanguage = $languageCode === '' ? null : app(Hata\Support\Localization\ContentLocales::class)->language($languageCode);
        $defaultLanguage = app(Hata\Support\Localization\ContentLocales::class)->default();
    @endphp

    <flux:modal name="add-language" class="w-full max-w-lg">
        <div
            class="space-y-4"
            x-data="{
                search: '',
                matches(text) {
                    return this.search.trim() === '' || text.includes(this.search.trim().toLowerCase());
                },
                get nothing() {
                    return this.search.trim() !== '' && ! Array.from(this.$refs.list.children).some((item) => item.dataset.search.includes(this.search.trim().toLowerCase()));
                },
            }"
        >
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('hata::site_settings.languages.add_heading') }}</flux:heading>
                <flux:text>{{ __('hata::site_settings.languages.add_description') }}</flux:text>
            </div>

            <flux:input x-model="search" icon="magnifying-glass" :placeholder="__('hata::site_settings.languages.search')" :aria-label="__('hata::site_settings.languages.search')" autofocus data-test="language-search" />

            <ul x-ref="list" class="max-h-80 divide-y divide-zinc-200 overflow-y-auto rounded-lg border border-zinc-200 dark:divide-white/10 dark:border-white/10" data-test="language-catalog">
                @foreach ($this->catalogLanguages as $language)
                    <li wire:key="catalog-{{ $language->code }}" data-search="{{ mb_strtolower($language->native.' '.$language->name.' '.$language->code) }}" x-show="matches($el.dataset.search)">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-zinc-50 dark:hover:bg-white/5"
                            wire:click="addLanguage('{{ $language->code }}')"
                            data-test="add-language-{{ $language->code }}"
                        >
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-800 dark:text-white" lang="{{ $language->hreflang() }}">{{ $language->native }}</span>
                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $language->name }}</span>
                            </span>
                            <span class="font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $language->code }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>

            <flux:text size="sm" x-show="nothing" x-cloak>{{ __('hata::site_settings.languages.search_empty') }}</flux:text>

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('hata::common.actions.close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <x-hata::confirm-modal
        wire:model.self="showLanguageModal"
        :heading="$confirmLanguage ? __('hata::site_settings.languages.confirm.'.$languageAction.'.heading', ['language' => $confirmLanguage->name]) : ''"
        :confirm-label="$confirmLanguage ? __('hata::site_settings.languages.confirm.'.$languageAction.'.submit') : ''"
        confirm-action="runLanguageAction"
    >
        @if ($confirmLanguage)
            {{ __('hata::site_settings.languages.confirm.'.$languageAction.'.body', [
                'language' => $confirmLanguage->name,
                'code' => $confirmLanguage->code,
                'old' => $defaultLanguage->name,
                'old_code' => $defaultLanguage->code,
            ]) }}
        @endif

        @if ($confirmLanguage && $languageAction === 'default' && $confirmLanguage->hasInterface())
            <x-slot:extra>
                <flux:checkbox
                    wire:model="languageAlsoInterface"
                    :label="__('hata::site_settings.languages.confirm.default.also_interface')"
                    :description="__('hata::site_settings.languages.confirm.default.also_interface_description')"
                    data-test="language-also-interface"
                />
            </x-slot:extra>
        @endif
    </x-hata::confirm-modal>
</div>
