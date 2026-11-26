<div class="max-w-56 text-sm text-zinc-600 dark:text-zinc-300" data-test="entry-terms-{{ $record->id }}">
    @forelse ($record->terms->groupBy('type') as $termType => $terms)
        <div class="truncate" title="{{ $terms->pluck('title')->implode(', ') }}">{{ $terms->pluck('title')->implode(', ') }}</div>
    @empty
        <span class="text-zinc-400">—</span>
    @endforelse
</div>
