{{--
    A term (a category, a tag) on the public site: its name, its description and the public records filed under it.
    Receives $entry (the term), $records (a simple paginator), $preview, $languageOff and $editUrl.
--}}
@extends('hata::layouts.site', [
    'pageTitle' => $entry->documentTitle(),
    'noindex' => $preview,
])

@section('strip')
    @include('hata::partials.site.preview-strip')
@endsection

@section('content')
    <header class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $entry->typeLabel() }}</p>
            <h1 class="text-3xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white sm:text-4xl">{{ $entry->title }}</h1>
        </div>

        @if ($editUrl !== null)
            <a href="{{ $editUrl }}" class="mt-2 shrink-0 rounded-md border border-zinc-200 px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">{{ __('content.public.edit') }}</a>
        @endif
    </header>

    @if (filled($entry->excerpt))
        <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">{{ $entry->excerpt }}</p>
    @endif

    @include('hata::partials.site.record-cards', ['records' => $records])
@endsection
