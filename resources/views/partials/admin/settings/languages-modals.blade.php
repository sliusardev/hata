{{-- The dialogs of Site settings → Languages. Outside the settings form, so Enter in the search never submits it. --}}
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
            <flux:heading size="lg">{{ __('site_settings.languages.add_heading') }}</flux:heading>
            <flux:text>{{ __('site_settings.languages.add_description') }}</flux:text>
        </div>

        <flux:input x-model="search" icon="magnifying-glass" :placeholder="__('site_settings.languages.search')" :aria-label="__('site_settings.languages.search')" autofocus data-test="language-search" />

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

        <flux:text size="sm" x-show="nothing" x-cloak>{{ __('site_settings.languages.search_empty') }}</flux:text>

        <div class="flex justify-end">
            <flux:modal.close>
                <flux:button variant="outline">{{ __('common.actions.close') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</flux:modal>

<x-confirm-modal
    wire:model.self="showLanguageModal"
    :heading="$confirmLanguage ? __('site_settings.languages.confirm.'.$languageAction.'.heading', ['language' => $confirmLanguage->name]) : ''"
    :confirm-label="$confirmLanguage ? __('site_settings.languages.confirm.'.$languageAction.'.submit') : ''"
    confirm-action="runLanguageAction"
>
    @if ($confirmLanguage)
        {{ __('site_settings.languages.confirm.'.$languageAction.'.body', [
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
                :label="__('site_settings.languages.confirm.default.also_interface')"
                :description="__('site_settings.languages.confirm.default.also_interface_description')"
                data-test="language-also-interface"
            />
        </x-slot:extra>
    @endif
</x-confirm-modal>
