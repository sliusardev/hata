{{--
    A page of public records as cards (a type's list, a term's page), with "newer" and "older" links.
    Receives $records, a simple paginator of records with their image loaded; makes no query.
--}}
@if ($records->isEmpty())
    <p class="mt-8 text-zinc-500 dark:text-zinc-400" data-test="list-empty">{{ __('content.public.list_empty') }}</p>
@else
    <ul class="mt-8 grid gap-8 sm:grid-cols-2" data-test="record-cards">
        @foreach ($records as $record)
            <li>
                <a href="{{ $record->url() }}" class="group block">
                    @if ($record->image?->isImage())
                        <img src="{{ $record->image->thumbUrl() ?? $record->image->url() }}" alt="{{ $record->image->altFor(app()->getLocale()) }}" loading="lazy" decoding="async" class="mb-3 aspect-[3/2] w-full rounded-xl bg-zinc-100 object-cover dark:bg-zinc-800">
                    @endif
                    <h2 class="text-lg font-semibold leading-snug text-zinc-900 group-hover:underline dark:text-white">{{ $record->title }}</h2>
                </a>
                @if ($record->published_at !== null)
                    <time datetime="{{ $record->published_at->toDateString() }}" class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">{{ $record->published_at->tz(config('admin.timezone'))->isoFormat('LL') }}</time>
                @endif
                @if (filled($record->excerpt))
                    <p class="mt-2 text-zinc-600 dark:text-zinc-300">{{ $record->excerpt }}</p>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($records->previousPageUrl() !== null || $records->hasMorePages())
        <nav class="mt-10 flex items-center justify-between gap-4 text-sm" aria-label="{{ __('content.public.pages') }}" data-test="list-pages">
            <span>
                @if ($records->previousPageUrl() !== null)
                    <a href="{{ $records->previousPageUrl() }}" rel="prev" class="rounded-lg border border-zinc-200 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">← {{ __('content.public.newer') }}</a>
                @endif
            </span>
            <span>
                @if ($records->hasMorePages())
                    <a href="{{ $records->nextPageUrl() }}" rel="next" class="rounded-lg border border-zinc-200 px-3 py-1.5 text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800">{{ __('content.public.older') }} →</a>
                @endif
            </span>
        </nav>
    @endif
@endif
