{{--
    The grid of language codes of one record group, four per row (the list's translations hover, the form's
    "Language and translations" card).

    Receives:
    - $entry         the record the grid belongs to (its own language is marked);
    - $languages     list<App\Support\Localization\Language>, every language added to the site
                     (ContentLocales::all(), the switched-off ones included);
    - $groupEntries  the record's loaded `groupEntries` (each with id, locale, title, status, published_at);
                     no query is made here;
    - $canCreate     optional bool, whether the actor holds the type's `create` permission
                     (checked with the policy when not passed);
    - $trashedEntries optional, the group's trashed records keyed by language (the form passes them; the list
                     does not, so there a trashed translation counts as missing);
    - $fromId        optional, the record the create links copy (`from`); `$entry->id` when not passed (the form
                     of a new translation passes its source, as its own record is not saved yet).

    A language with a record links to that record's edit page: green when it is published, amber when it is a
    draft or scheduled, muted and struck through when it is in the trash. A language without one is dashed with "+" and links to `admin.content.create` with
    `from` and `locale`, or is a plain mark without the create permission. A switched-off language is muted
    with an "off" mark. Below the grid, a legend with the counts.
--}}
@php
    $canCreate ??= auth()->user()?->can('create', [App\Models\Entry::class, $entry->type]) ?? false;
    $trashedByLocale = collect($trashedEntries ?? []);
    $fromId ??= $entry->id;
    $byLocale = collect($groupEntries)->keyBy('locale');
    $activeCodes = array_map(fn ($language) => $language->code, app(App\Support\Localization\ContentLocales::class)->active());
    $tally = ['published' => 0, 'draft' => 0, 'missing' => 0, 'trashed' => 0];
    $cell = 'relative flex min-h-8 flex-col items-center justify-center rounded-md px-1 py-1 text-center text-xs font-semibold uppercase leading-tight transition-colors';
@endphp

<div class="grid grid-cols-4 gap-2" data-test="translations-grid-{{ $entry->id }}">
    @foreach ($languages as $language)
        @php
            $record = $language->code === $entry->locale ? $entry : $byLocale->get($language->code);
            $binned = $record === null ? $trashedByLocale->get($language->code) : null;
            $off = ! in_array($language->code, $activeCodes, true);
            $state = $record?->state();
            $kind = match (true) {
                $binned !== null => 'trashed',
                $record === null => 'missing',
                $state === App\Enums\EntryState::Published => 'published',
                default => 'draft',
            };
            $tally[$kind]++;
            $label = match (true) {
                $binned !== null => __('content.grid.trashed', ['language' => $language->native]).' — '.$binned->title,
                $record !== null && $record->is($entry) => __('content.grid.this', ['language' => $language->native]),
                $kind === 'published' => __('content.grid.published', ['language' => $language->native]).' — '.$record->title,
                $state === App\Enums\EntryState::Scheduled => __('content.grid.scheduled', ['language' => $language->native]).' — '.$record->title,
                $kind === 'draft' => __('content.grid.draft', ['language' => $language->native]).' — '.$record->title,
                $canCreate => __('content.grid.missing', ['language' => $language->native]),
                default => __('content.grid.none', ['language' => $language->native]),
            };
            $offMark = $off ? '<span class="block text-[9px] font-normal normal-case opacity-80">'.e(__('content.grid.off')).'</span>' : '';
        @endphp

        @if ($record !== null && $record->is($entry))
            <span title="{{ $label }}" @class([$cell, 'bg-zinc-900 text-white ring-2 ring-zinc-900 ring-offset-2 ring-offset-white dark:bg-white dark:text-zinc-900 dark:ring-white dark:ring-offset-zinc-900', 'opacity-60' => $off]) aria-current="true" data-test="grid-cell-{{ $language->code }}" data-state="this" wire:key="grid-{{ $entry->id }}-{{ $language->code }}">
                {{ $language->code }}{!! $offMark !!}
            </span>
        @elseif ($binned !== null)
            <a href="{{ route('admin.content.edit', ['type' => $entry->type, 'entry' => $binned->id]) }}" wire:navigate title="{{ $label }}" @class([$cell, 'bg-zinc-100 text-zinc-400 line-through decoration-1 hover:bg-zinc-200 dark:bg-white/5 dark:text-zinc-500 dark:hover:bg-white/10', 'opacity-60' => $off]) data-test="grid-cell-{{ $language->code }}" data-state="trashed" wire:key="grid-{{ $entry->id }}-{{ $language->code }}">
                <span class="flex items-center gap-0.5"><flux:icon.trash variant="micro" class="size-3 no-underline" />{{ $language->code }}</span>{!! $offMark !!}
            </a>
        @elseif ($record !== null)
            <a href="{{ route('admin.content.edit', ['type' => $entry->type, 'entry' => $record->id]) }}" wire:navigate title="{{ $label }}" @class([
                $cell,
                'bg-green-50 text-green-700 hover:bg-green-100 dark:bg-green-500/15 dark:text-green-300 dark:hover:bg-green-500/25' => $kind === 'published',
                'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-500/15 dark:text-amber-300 dark:hover:bg-amber-500/25' => $kind === 'draft',
                'opacity-60' => $off,
            ]) data-test="grid-cell-{{ $language->code }}" data-state="{{ $kind }}" wire:key="grid-{{ $entry->id }}-{{ $language->code }}">
                {{ $language->code }}{!! $offMark !!}
            </a>
        @elseif ($canCreate)
            <a href="{{ route('admin.content.create', ['type' => $entry->type, 'from' => $fromId, 'locale' => $language->code]) }}" wire:navigate title="{{ $label }}" @class([$cell, 'border border-dashed border-zinc-300 text-zinc-400 hover:border-zinc-500 hover:text-zinc-700 dark:border-zinc-600 dark:text-zinc-500 dark:hover:border-zinc-400 dark:hover:text-zinc-200', 'opacity-60' => $off]) data-test="grid-cell-{{ $language->code }}" data-state="missing" wire:key="grid-{{ $entry->id }}-{{ $language->code }}">
                <span>+ {{ $language->code }}</span>{!! $offMark !!}
            </a>
        @else
            <span title="{{ $label }}" @class([$cell, 'border border-dashed border-zinc-300 text-zinc-400 dark:border-zinc-600 dark:text-zinc-500', 'opacity-60' => $off]) data-test="grid-cell-{{ $language->code }}" data-state="missing" wire:key="grid-{{ $entry->id }}-{{ $language->code }}">
                {{ $language->code }}{!! $offMark !!}
            </span>
        @endif
    @endforeach
</div>

<div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs font-normal text-zinc-500 dark:text-zinc-400">
    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-500"></span>{{ __('content.grid.legend.published') }} {{ $tally['published'] }}</span>
    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-400"></span>{{ __('content.grid.legend.draft') }} {{ $tally['draft'] }}</span>
    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full border border-dashed border-zinc-400"></span>{{ __('content.grid.legend.missing') }} {{ $tally['missing'] }}</span>
    @if ($tally['trashed'] > 0)
        <span class="flex items-center gap-1.5"><flux:icon.trash variant="micro" class="size-3 text-zinc-400" />{{ __('content.grid.legend.trashed') }} {{ $tally['trashed'] }}</span>
    @endif
</div>
