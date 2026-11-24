@props(['test' => null])
{{-- The amber strip over the public site that only an editor sees (the preview strip, the home page's hint). --}}
<div class="border-b border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" @if ($test) data-test="{{ $test }}" @endif>
    <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm sm:px-6">
        {{ $slot }}
    </div>
</div>
