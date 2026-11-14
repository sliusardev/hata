{{--
    Files of a read-only form, without picker buttons. Receives $label and $ids (media ids); the files come from the
    page's `readOnlyMedia` (one query for the whole form). A file that is gone is left out.
--}}
@php($files = collect($ids)->map(fn ($id) => $this->readOnlyMedia[$id] ?? null)->filter())

<div class="space-y-2" role="group" aria-label="{{ $label }}">
    <flux:label>{{ $label }}</flux:label>

    @forelse ($files as $file)
        <div class="flex items-center gap-3 rounded-lg border border-zinc-200 p-2 dark:border-zinc-700" wire:key="ro-media-{{ $file->id }}">
            @if ($file->isImage())
                <img src="{{ $file->thumbUrl() }}" alt="" loading="lazy" class="size-10 shrink-0 rounded-md bg-zinc-100 object-cover dark:bg-zinc-700" />
            @else
                <div class="flex size-10 shrink-0 items-center justify-center rounded-md bg-zinc-100 dark:bg-zinc-700/60">
                    <flux:icon.document variant="micro" class="text-zinc-400" />
                </div>
            @endif
            <span class="min-w-0 truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $file->name }}</span>
        </div>
    @empty
        <flux:text class="text-sm">{{ __('content.form.no_file') }}</flux:text>
    @endforelse
</div>
