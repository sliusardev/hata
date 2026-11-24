{{--
    The home of a language where the chosen page is not public (a draft, in the trash, or not translated yet): the
    site name and the language's site description, in layouts/site's reading column. Indexed, unlike the Hata
    introduction. No query of its own: the head and the description come from PageMeta (HomeController).
    Receives $hint (the strip for a user who may edit the settings).
--}}
@extends('hata::layouts.site', [
    // Kept for a site whose overridden layouts/site still prints the old variables (the 0.3 line).
    'pageTitle' => app(Hata\Support\Seo\PageMeta::class)->title(),
    'noindex' => false,
])

@section('strip')
    @include('hata::partials.site.home-hint')
@endsection

@section('content')
    @php($description = app(Hata\Support\Seo\PageMeta::class)->description())

    <div data-test="home-plain">
        <h1 class="text-3xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white sm:text-4xl">{{ config('app.name') }}</h1>

        @if ($description !== null)
            <p class="mt-4 text-lg leading-relaxed text-zinc-600 dark:text-zinc-300">{{ $description }}</p>
        @endif
    </div>
@endsection
