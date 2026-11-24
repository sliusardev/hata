{{--
    The Hata introduction, shown only while no home page is chosen in Site settings (where a chosen page is not
    public, site/home-plain is shown instead): a small landing page, full width in layouts/site ('page'), not indexed;
    "Open the admin" is for signed-in users only. Flat on warm paper: no shadows, hairlines, pill buttons, system fonts
    only; the palette is the "home" tokens in resources/css/site.css (light and dark).
    No query of its own: the head comes from PageMeta (HomeController).
--}}
@extends('hata::layouts.site', [
    // Kept for a site whose overridden layouts/site still prints the old variables (the 0.3 line).
    'pageTitle' => app(Hata\Support\Seo\PageMeta::class)->title(),
    'noindex' => true,
])

@section('strip')
    @include('hata::partials.site.home-hint')
@endsection

@php
    $features = [
        'content' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        'languages' => 'm10.5 21 5.25-11.25L21 21m-9-3h7.5M3 5.621a48.474 48.474 0 0 1 6-.371m0 0c1.12 0 2.233.038 3.334.114M9 5.25V3m3.334 2.364C11.176 10.658 7.69 15.08 3 17.502m9.334-12.138c.896.061 1.785.147 2.666.257m-4.589 8.495a18.023 18.023 0 0 1-3.827-5.802',
        'media' => 'm2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
        'seo' => 'm21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z',
        'users' => 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
        'settings' => 'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75',
    ];
    $label = 'font-home-mono text-[13px] font-semibold uppercase tracking-[0.05em]';
    $pill = 'inline-flex items-center justify-center gap-2 rounded-[48px] px-6 py-3 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-home-cta';
@endphp

@section('page')
    <div class="mx-auto max-w-[1200px] px-4 sm:px-8" data-test="hata-intro">
        {{-- Hero --}}
        <section class="grid items-center gap-12 pt-12 sm:pt-20 lg:grid-cols-[1.1fr_1fr] lg:gap-16 lg:pt-22">
            <div>
                <p class="{{ $label }} inline-flex items-center gap-2.5 rounded-full border border-home-pill bg-home-mint px-5 py-2.5 text-home-ink">
                    <span class="size-2 rounded-full bg-home-sage" aria-hidden="true"></span>
                    {{ __('hata::content.public.home.eyebrow') }}
                </p>

                <h1 class="mt-7 font-home-serif text-[2.5rem] leading-[1.15] font-normal tracking-tight text-home-ink sm:text-[3.25rem] lg:text-[4rem] lg:leading-[1.2]">
                    {{ __('hata::content.public.home.headline') }}
                    <em class="font-bold text-home-accent italic">{{ __('hata::content.public.home.headline_accent') }}</em>
                </h1>

                <p class="mt-6 max-w-xl text-base leading-[1.6] text-home-muted sm:text-lg">
                    {{ __('hata::content.public.home.lead') }}
                </p>

                <div class="mt-9 flex flex-wrap gap-3">
                    @auth
                        <a href="{{ route('admin.dashboard') }}" class="{{ $pill }} bg-home-cta text-home-cta-ink hover:bg-home-cta-hover" data-test="home-admin-link">
                            {{ __('hata::content.public.home.admin') }}
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                            </svg>
                        </a>
                    @endauth
                    <a href="https://github.com/sliusardev/hata" class="{{ $pill }} border border-home-ghost text-home-ghost hover:bg-home-mint">
                        {{ __('hata::content.public.home.docs') }}
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m4.5 19.5 15-15m0 0H8.25m11.25 0v11.25" />
                        </svg>
                    </a>
                </div>

                <p class="mt-6 flex max-w-xl items-start gap-2 text-sm leading-[1.5] text-home-muted">
                    <svg class="mt-0.5 size-4 shrink-0 text-home-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <span>{{ __('hata::content.public.home.next') }}</span>
                </p>
            </div>

            {{-- A hand-drawn browser window with a schematic page, and a few doodles around it. --}}
            <div class="mx-auto w-full max-w-md lg:max-w-none">
                <svg class="h-auto w-full" viewBox="0 0 480 380" fill="none" aria-hidden="true">
                    <g class="stroke-home-stroke" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        {{-- Doodles --}}
                        <path class="fill-home-sage" d="M452 8l4.2 9.6 10.4 1.2-7.8 7 2.2 10.2-9-5.2-9 5.2 2.2-10.2-7.8-7 10.4-1.2z" />
                        <circle class="fill-home-rose" cx="18" cy="250" r="9" />
                        <path d="M14 86v14M7 93h14" />
                        <path d="M358 368c34 8 66-2 88-18m0 0-12 1m12-1-2 12" />
                        <circle class="fill-home-foam" cx="470" cy="210" r="6" />

                        {{-- Window --}}
                        <path class="fill-home-mint" d="M38 66c-1-15 7-24 22-25l354-3c17 0 27 9 27 25l1 256c0 16-9 25-25 25l-353 2c-17 0-26-9-26-26z" />
                        <path d="M39 88c120-3 270 2 402-2" />
                        <circle class="fill-home-rose" cx="64" cy="64" r="6" />
                        <circle class="fill-home-sage" cx="86" cy="64" r="6" />
                        <circle class="fill-home-foam" cx="108" cy="64" r="6" />
                        <rect class="fill-home-paper" x="140" y="54" width="210" height="20" rx="10" />

                        {{-- Page --}}
                        <path stroke-width="10" d="M74 122h170" />
                        <path class="stroke-home-rose" stroke-width="8" d="M74 146h118" />
                        <path class="stroke-home-pill" stroke-width="5" d="M74 176h196M74 194h182M74 212h140" />
                        <rect class="fill-home-cta" x="74" y="234" width="88" height="26" rx="13" />

                        <rect class="fill-home-foam" x="296" y="108" width="116" height="112" rx="10" />
                        <path class="fill-home-accent" d="M306 208l36-46 24 30 16-18 22 34z" />
                        <circle class="fill-home-rose" cx="386" cy="132" r="9" />

                        <rect class="fill-home-paper" x="74" y="284" width="100" height="40" rx="8" />
                        <rect class="fill-home-paper" x="190" y="284" width="100" height="40" rx="8" />
                        <rect class="fill-home-paper" x="306" y="284" width="106" height="40" rx="8" />
                        <path class="stroke-home-pill" stroke-width="4" d="M88 298h52M88 310h36M204 298h52M204 310h40M320 298h56M320 310h32" />
                    </g>
                </svg>
            </div>
        </section>

        {{-- Features --}}
        <section class="pt-16 sm:pt-22" aria-labelledby="home-features">
            <div class="flex items-center gap-4">
                <h2 id="home-features" class="{{ $label }} shrink-0 text-home-ink">{{ __('hata::content.public.home.features_title') }}</h2>
                <span class="h-px flex-1 bg-home-line" aria-hidden="true"></span>
            </div>

            <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $key => $icon)
                    <article class="rounded-xl bg-home-mint p-6 sm:p-8">
                        <span class="flex size-11 items-center justify-center rounded-full bg-home-foam text-home-accent" aria-hidden="true">
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $icon }}" />
                            </svg>
                        </span>
                        <h3 class="mt-5 font-home-serif text-xl font-semibold text-home-ink">{{ __('hata::content.public.home.features.'.$key.'.title') }}</h3>
                        <p class="mt-2 text-[15px] leading-[1.6] text-home-muted">{{ __('hata::content.public.home.features.'.$key.'.text') }}</p>
                    </article>
                @endforeach
            </div>
        </section>
    </div>

    {{-- Stats --}}
    <section class="mt-16 border-t border-home-line bg-home-sand sm:mt-22">
        <p class="mx-auto flex max-w-[1200px] flex-wrap items-center justify-center gap-x-5 gap-y-2 px-4 py-7 text-center font-home-mono text-[13px] font-medium tracking-[0.05em] text-home-ink uppercase sm:px-8">
            <span>Laravel {{ Illuminate\Support\Str::before(app()->version(), '.') }}</span>
            <span class="text-home-pill" aria-hidden="true">|</span>
            <span>Livewire 4</span>
            <span class="text-home-pill" aria-hidden="true">|</span>
            <span>{{ __('hata::content.public.home.open_source') }}</span>
        </p>
    </section>
@endsection
