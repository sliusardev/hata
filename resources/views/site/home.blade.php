{{-- The neutral home page, until a page is chosen in Site settings (or where the chosen one is not public). --}}
@extends('hata::layouts.site')

@section('strip')
    @include('hata::partials.site.home-hint')
@endsection

@section('content')
    @php($description = app(Hata\Support\Seo\PageMeta::class)->description())

    <div class="py-10 text-center sm:py-16">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">{{ config('app.name') }}</h1>
        @if (filled($description))
            <p class="mx-auto mt-4 max-w-xl text-lg text-zinc-600 dark:text-zinc-400">{{ $description }}</p>
        @endif
    </div>
@endsection
