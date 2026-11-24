<!DOCTYPE html>
{{--
    The neutral layout of the public site until themes replace it. Plain HTML: no Livewire, no Flux scripts and no
    admin JavaScript; only the site's own CSS (resources/css/site.css, not the admin's app.css), the color scheme and
    a two-line appearance check for dark mode.
    The head's title, description, canonical, Open Graph and hreflang come from PageMeta through @hataHead (a
    controller fills it); a section 'strip' is optional.
--}}
@php
    $contentLocales = app(Hata\Support\Localization\ContentLocales::class);
    $language = $contentLocales->current();
@endphp
<html lang="{{ $language->hreflang() }}" dir="{{ $language->direction() }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />

        @hataHead

        @include('hata::partials.favicons')

        @hataFonts

        @hataAssets(['resources/css/site.css'])
        @include('hata::partials.color-scheme')

        {{-- Dark mode as the admin sets it (Flux's stored choice, else the system's), without loading Flux. --}}
        <style>:root.dark { color-scheme: dark; }</style>
        <script>
            (function () {
                var appearance = null;
                try { appearance = window.localStorage.getItem('flux.appearance'); } catch (e) {}
                if (appearance === 'dark' || (appearance !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>
    </head>
    <body class="min-h-screen bg-white font-sans text-zinc-800 antialiased dark:bg-zinc-900 dark:text-zinc-200">
        @yield('strip')

        <header class="border-b border-zinc-200 dark:border-zinc-700">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
                <a href="{{ $contentLocales->route('home') }}" class="flex min-w-0 items-center gap-2 font-semibold text-zinc-900 dark:text-white">
                    @if (Hata\Support\Settings\SiteLogo::isCustom())
                        <x-hata::site-logo class="h-8" />
                    @else
                        <span class="truncate">{{ config('app.name', 'Laravel') }}</span>
                    @endif
                </a>

                @include('hata::partials.site.language-switcher')
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
            @yield('content')
        </main>
    </body>
</html>
