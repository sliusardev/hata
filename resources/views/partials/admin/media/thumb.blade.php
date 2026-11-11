{{--
    A file's square preview: its thumbnail, or its kind's icon with the extension. Fills its parent.
    Expects: $item (Media); optional $compact (smaller icon and no extension, for small previews).
--}}
@if ($thumbUrl = $item->thumbUrl())
    <img src="{{ $thumbUrl }}" alt="{{ $item->altFor(app()->getLocale()) }}" loading="lazy" draggable="false" class="size-full object-cover" />
@elseif ($compact ?? false)
    <div class="flex size-full items-center justify-center text-zinc-400 dark:text-zinc-500">
        <flux:icon :name="$item->kind->icon()" variant="outline" class="size-6" />
    </div>
@else
    <div class="flex size-full flex-col items-center justify-center gap-2 text-zinc-400 dark:text-zinc-500">
        <flux:icon :name="$item->kind->icon()" variant="outline" class="size-10" />
        <span class="text-xs font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $item->extension() }}</span>
    </div>
@endif
