{{--
    The strip over a page only an editor sees: a draft, a scheduled record, or any page of a language that is switched
    off. Receives $entry, $preview, $languageOff and $editUrl.
--}}
@if ($preview)
    <div class="border-b border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-test="preview-strip">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-2.5 text-sm sm:px-6">
            <p>
                <span class="font-semibold">
                    @if ($languageOff && $entry->isPublic())
                        {{ __('content.public.language_off') }}
                    @elseif ($entry->state() === Hata\Enums\EntryState::Scheduled)
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
