{{--
    The list of a type's public records at its base address (`/posts`), newest first.
    Receives $type (the ContentType) and $records (a simple paginator).
--}}
@extends('hata::layouts.site', [
    // Kept for a site whose overridden layouts/site still prints the old variables (the 0.3 line).
    'pageTitle' => app(Hata\Support\Seo\PageMeta::class)->title(),
    'noindex' => app(Hata\Support\Seo\PageMeta::class)->isNoindex(),
])

@section('content')
    <h1 class="text-3xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white sm:text-4xl">{{ $type->pluralLabel() }}</h1>

    @include('hata::partials.site.record-cards', ['records' => $records])
@endsection
