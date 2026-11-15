{{--
    A content record on the public site: title, image and body. The type's own fields are not printed here.
    Receives $entry (with its image loaded), $body (rendered), $galleries (field name => images), $terms (taxonomy key => the record's terms), $preview, $languageOff (the page's language is switched off) and $editUrl (null without the type's edit).
--}}
@extends('layouts.site', [
    'pageTitle' => $entry->documentTitle(),
    'noindex' => $preview,
])

@section('strip')
    @if ($preview)
        <div class="border-b border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-test="preview-strip">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm sm:px-6">
                <p>
                    <span class="font-semibold">
                        @if ($languageOff && $entry->isPublic())
                            {{ __('content.public.language_off') }}
                        @elseif ($entry->state() === App\Enums\EntryState::Scheduled)
                            {{ __('content.public.scheduled', ['date' => $entry->published_at->tz(config('admin.timezone'))->isoFormat('LLL')]) }}
                        @else
                            {{ __('content.public.draft') }}
                        @endif
                    </span>
                    <span class="text-amber-800 dark:text-amber-300">· {{ __('content.public.preview_note') }}</span>
                </p>
                <a href="{{ $editUrl }}" class="font-medium underline underline-offset-2">{{ __('content.public.edit') }}</a>
            </div>
        </div>
    @endif
@endsection

@section('content')
    <article>
        <header class="flex items-start justify-between gap-4">
            <h1 class="text-3xl font-semibold leading-tight tracking-tight text-zinc-900 dark:text-white sm:text-4xl">{{ $entry->title }}</h1>

            @if ($editUrl !== null && ! $preview)
                <a href="{{ $editUrl }}" class="mt-2 shrink-0 rounded-md border border-zinc-200 px-2 py-1 text-xs text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">{{ __('content.public.edit') }}</a>
            @endif
        </header>

        @if ($entry->image?->isImage())
            <img
                src="{{ $entry->image->url() }}"
                @if ($entry->image->width !== null && $entry->image->height !== null)
                    width="{{ $entry->image->width }}"
                    height="{{ $entry->image->height }}"
                @endif
                alt="{{ $entry->image->altFor(app()->getLocale()) }}"
                class="mt-8 h-auto w-full rounded-xl"
            >
        @endif

        @if ($body->toHtml() !== '')
            <div class="rich-text-content mt-8">{{ $body }}</div>
        @endif

        {{-- The terms the record is filed under, by taxonomy, each linking to its page. --}}
        @if ($terms !== [])
            <dl class="mt-8 space-y-2 border-t border-zinc-200 pt-6 text-sm dark:border-zinc-700" data-test="entry-terms">
                @foreach ($terms as $taxonomyKey => $taxonomyTerms)
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ $taxonomyTerms[0]->type()->pluralLabel() }}:</dt>
                        <dd class="flex flex-wrap gap-1.5">
                            @foreach ($taxonomyTerms as $term)
                                <a href="{{ $term->url() }}" class="rounded-md bg-zinc-100 px-2 py-0.5 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700">{{ $term->title }}</a>
                            @endforeach
                        </dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- Gallery fields of the type: a grid of the images' thumbnails, each opening the full file. --}}
        @foreach ($galleries as $name => $images)
            <ul class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3" data-test="gallery-{{ $name }}">
                @foreach ($images as $image)
                    <li>
                        <a href="{{ $image->url() }}" class="block overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                            <img src="{{ $image->thumbUrl() ?? $image->url() }}" alt="{{ $image->altFor(app()->getLocale()) }}" loading="lazy" decoding="async" class="aspect-square h-full w-full object-cover transition hover:opacity-90">
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </article>
@endsection
