{{--
    The languages of one record group as a line of codes, each a link: the list's row and the form's header.

    Receives:
    - $type        the type key;
    - $languages   list<Hata\Support\Localization\Language>, every language added to the site;
    - $current     the code of the record the line belongs to (filled dark, not a link), or null;
    - $records     the group's live records (id, locale, title, status, published_at); no query is made here;
    - $trashed     optional, the group's trashed records keyed by language (the form passes them; without them a
                   trashed translation counts as missing, and its create link opens the trashed record);
    - $canCreate   whether the actor may create a translation;
    - $fromId      the record the create links copy (`from`), or null (no create links);
    - $activeCodes the codes of the switched-on languages.

    Green is published, amber a draft or scheduled, struck through in the trash, dashed with "+" a language to
    create (a plain dashed code without the right to). A switched-off language is dimmed. Each chip carries
    `data-state` (`this`, `published`, `draft`, `trashed`, `missing`).
--}}
@php
    $byLocale = collect($records)->keyBy('locale');
    $trashedByLocale = collect($trashed ?? []);
    $chip = 'inline-flex h-5 min-w-7 items-center justify-center rounded px-1 text-[11px] font-semibold uppercase leading-none transition-colors';
@endphp

<span class="flex flex-wrap items-center gap-1" data-test="translation-chips">
    @foreach ($languages as $language)
        @php
            $code = $language->code;
            $record = $byLocale->get($code);
            $binned = $record === null ? $trashedByLocale->get($code) : null;
            $state = $record?->state();
            $dim = in_array($code, $activeCodes, true) ? '' : 'opacity-50';
        @endphp

        @if ($code === $current)
            <span class="{{ $chip }} {{ $dim }} bg-zinc-800 text-white dark:bg-white dark:text-zinc-900" title="{{ __('hata::content.grid.this', ['language' => $language->native]) }}" aria-current="true" data-test="chip-{{ $code }}" data-state="this">{{ $code }}</span>
        @elseif ($record !== null)
            @php $published = $state === Hata\Enums\EntryState::Published; @endphp
            <a
                href="{{ route('admin.content.edit', ['type' => $type, 'entry' => $record->id]) }}"
                wire:navigate
                title="{{ __('hata::content.grid.'.strtolower($state->name), ['language' => $language->native]) }} — {{ $record->title }}"
                @class([$chip, $dim, 'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-500/15 dark:text-green-300 dark:hover:bg-green-500/25' => $published, 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-300 dark:hover:bg-amber-500/25' => ! $published])
                data-test="chip-{{ $code }}" data-state="{{ $published ? 'published' : 'draft' }}"
            >{{ $code }}</a>
        @elseif ($binned !== null)
            <a
                href="{{ route('admin.content.edit', ['type' => $type, 'entry' => $binned->id]) }}"
                wire:navigate
                title="{{ __('hata::content.grid.trashed', ['language' => $language->native]) }} — {{ $binned->title }}"
                class="{{ $chip }} {{ $dim }} bg-zinc-100 text-zinc-400 line-through hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:hover:bg-white/10"
                data-test="chip-{{ $code }}" data-state="trashed"
            >{{ $code }}</a>
        @elseif ($canCreate && $fromId !== null)
            <a
                href="{{ route('admin.content.create', ['type' => $type, 'from' => $fromId, 'locale' => $code]) }}"
                wire:navigate
                title="{{ __('hata::content.grid.missing', ['language' => $language->native]) }}"
                class="{{ $chip }} {{ $dim }} border border-dashed border-zinc-300 text-zinc-400 hover:border-zinc-500 hover:text-zinc-700 dark:border-zinc-600 dark:text-zinc-500 dark:hover:border-zinc-400 dark:hover:text-zinc-200"
                data-test="chip-{{ $code }}" data-state="missing"
            >+{{ $code }}</a>
        @else
            <span class="{{ $chip }} {{ $dim }} border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600 dark:text-zinc-500" title="{{ __('hata::content.grid.none', ['language' => $language->native]) }}" data-test="chip-{{ $code }}" data-state="missing">{{ $code }}</span>
        @endif
    @endforeach
</span>
