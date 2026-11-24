{{--
    The strip over a page only an editor sees: a draft, a scheduled record, or any page of a language that is switched
    off. Receives $entry, $preview, $languageOff and $editUrl.
--}}
@if ($preview)
    <x-hata::site.strip test="preview-strip">
        <p>
            <span class="font-semibold">
                @if ($languageOff && $entry->isPublic())
                    {{ __('hata::content.public.language_off') }}
                @elseif ($entry->state() === Hata\Enums\EntryState::Scheduled)
                    {{ __('hata::content.public.scheduled', ['date' => $entry->published_at->tz(config('hata.admin.timezone'))->isoFormat('LLL')]) }}
                @else
                    {{ __('hata::content.public.draft') }}
                @endif
            </span>
            <span class="text-amber-800 dark:text-amber-300">· {{ __('hata::content.public.preview_note') }}</span>
        </p>
        <a href="{{ $editUrl }}" class="font-medium underline underline-offset-2">{{ __('hata::content.public.edit') }}</a>
    </x-hata::site.strip>
@endif
