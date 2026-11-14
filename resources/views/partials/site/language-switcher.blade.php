{{--
    The public language switcher: plain links in a <details>, no JavaScript. Every active language links to the
    current page in it when there is one (the alternates), else to that language's home page.
--}}
@php
    $contentLocales = app(App\Support\Localization\ContentLocales::class);
    $languages = $contentLocales->active();
    $alternates = $contentLocales->alternates();
    $current = $contentLocales->current();
@endphp

@if (count($languages) > 1)
    <details class="group relative shrink-0" data-test="language-switcher">
        <summary class="flex cursor-pointer list-none items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800 [&::-webkit-details-marker]:hidden">
            <span class="sr-only">{{ __('content.public.language') }}:</span>
            <span>{{ $current->native }}</span>
            <svg class="size-4 text-zinc-400 transition group-open:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
            </svg>
        </summary>

        <ul class="absolute end-0 z-10 mt-2 max-h-80 min-w-44 overflow-y-auto rounded-xl border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
            @foreach ($languages as $language)
                @php($isCurrent = $language->code === $current->code)
                <li>
                    <a
                        href="{{ $isCurrent ? url()->current() : ($alternates[$language->code] ?? $contentLocales->route('home', [], $language->code)) }}"
                        hreflang="{{ $language->hreflang() }}"
                        lang="{{ $language->hreflang() }}"
                        @if ($isCurrent) aria-current="page" @endif
                        @class([
                            'block rounded-lg px-3 py-2 text-sm',
                            'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-700 dark:text-white' => $isCurrent,
                            'text-zinc-700 hover:bg-zinc-50 dark:text-zinc-200 dark:hover:bg-zinc-700/60' => ! $isCurrent,
                        ])
                    >{{ $language->native }}</a>
                </li>
            @endforeach
        </ul>
    </details>
@endif
