{{--
    The translations of one record on its form, a row per other language of the site (as in the language boxes of
    WordPress and Joomla): the linked record with its state, or the ways to get one.

    Receives:
    - $entry           the saved record, or null on the create page;
    - $type, $locale   the type key and the form's language (its row is left out);
    - $languages       list<Hata\Support\Localization\Language>, every language added to the site;
    - $groupEntries    the live records of the group (id, locale, title, status, published_at); no query is made here;
    - $trashedEntries  the group's trashed records keyed by language;
    - $canCreate       whether the actor may create a translation;
    - $canLink         whether the actor may choose an existing record or unlink one (a saved, editable record);
    - $fromId          the record the create links copy (`from`), or null.

    A language with a record shows its title as a link to its edit page, its state, and "unlink". A language
    without one offers "Choose" (the `choose-translation` event, which opens the translation picker) and "Create" (`admin.content.create` with `from`
    and `locale`). A record in the trash is struck through and only links to its page.
--}}
@php
    $byLocale = collect($groupEntries)->keyBy('locale');
    $trashedByLocale = collect($trashedEntries ?? []);
    $activeCodes = app(Hata\Support\Localization\ContentLocales::class)->activeCodes();
    $badge = 'mt-0.5 w-8 shrink-0 rounded bg-zinc-100 py-0.5 text-center text-[11px] font-semibold text-zinc-700 uppercase dark:bg-white/10 dark:text-zinc-200';
@endphp

<ul class="divide-y divide-zinc-200 dark:divide-zinc-700" data-test="translations-list">
    @foreach ($languages as $language)
        @continue($language->code === $locale)

        @php
            $record = $byLocale->get($language->code);
            $binned = $record === null ? $trashedByLocale->get($language->code) : null;
            $off = ! in_array($language->code, $activeCodes, true);
            $state = $record?->state();
        @endphp

        <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0" wire:key="translation-{{ $language->code }}" data-test="translation-row-{{ $language->code }}" data-state="{{ $binned !== null ? 'trashed' : ($record === null ? 'missing' : $state->name) }}">
            <span @class([$badge, 'opacity-60' => $off]) title="{{ $language->native }}">{{ $language->code }}</span>

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="truncate">{{ $language->native }}</span>
                    @if ($off)
                        <span class="shrink-0">· {{ __('hata::content.grid.off') }}</span>
                    @endif
                </div>

                @if ($record !== null)
                    <a href="{{ route('admin.content.edit', ['type' => $type, 'entry' => $record->id]) }}" wire:navigate class="block truncate text-sm font-medium text-zinc-900 hover:underline dark:text-white" title="{{ $record->title }}" data-test="translation-edit-{{ $language->code }}">{{ $record->title }}</a>
                    <span class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                        <span @class(['size-2 rounded-full', 'bg-green-500' => $state === Hata\Enums\EntryState::Published, 'bg-amber-400' => $state !== Hata\Enums\EntryState::Published])></span>
                        {{ __('hata::content.list.state.'.strtolower($state->name)) }}
                    </span>
                @elseif ($binned !== null)
                    <a href="{{ route('admin.content.edit', ['type' => $type, 'entry' => $binned->id]) }}" wire:navigate class="block truncate text-sm text-zinc-500 line-through hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white" title="{{ $binned->title }}" data-test="translation-edit-{{ $language->code }}">{{ $binned->title }}</a>
                    <span class="mt-0.5 flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400"><flux:icon.trash variant="micro" class="size-3" />{{ __('hata::content.grid.legend.trashed') }}</span>
                @else
                    <span class="block text-sm text-zinc-400 dark:text-zinc-500">{{ __('hata::content.translations.none') }}</span>
                    @if ($canLink || ($canCreate && $fromId !== null))
                        <div class="mt-2 flex flex-wrap gap-2">
                            @if ($canLink)
                                <flux:button size="xs" icon="magnifying-glass" x-on:click="Livewire.dispatch('choose-translation', { locale: @js($language->code) })" data-test="translation-choose-{{ $language->code }}">{{ __('hata::content.translations.choose') }}</flux:button>
                            @endif
                            @if ($canCreate && $fromId !== null)
                                <flux:button size="xs" icon="plus" :href="route('admin.content.create', ['type' => $type, 'from' => $fromId, 'locale' => $language->code])" wire:navigate data-test="translation-create-{{ $language->code }}">{{ __('hata::content.translations.create') }}</flux:button>
                            @endif
                        </div>
                    @endif
                @endif
            </div>

            @if ($record !== null)
                <div class="flex shrink-0 items-center">
                    <flux:button size="xs" variant="ghost" icon="pencil-square" :href="route('admin.content.edit', ['type' => $type, 'entry' => $record->id])" wire:navigate :title="__('hata::content.translations.edit')" :aria-label="__('hata::content.translations.edit')" />
                    @if ($canLink)
                        <flux:button size="xs" variant="ghost" icon="link-slash" x-on:click="$wire.confirmUnlink({{ $record->id }})" :title="__('hata::content.link.unlink')" :aria-label="__('hata::content.link.unlink')" data-test="translation-unlink-{{ $language->code }}" />
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ul>
