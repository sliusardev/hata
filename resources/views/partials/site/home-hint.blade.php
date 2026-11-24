{{-- The strip over the neutral home page, for a user who may change the site's settings. Receives $hint. --}}
@if ($hint)
    <x-hata::site.strip test="home-hint">
        <p class="font-semibold">
            {{ $hint === 'choose' ? __('hata::content.public.home.choose') : __('hata::content.public.home.unpublished') }}
        </p>
        <a href="{{ route('admin.settings.edit', ['tab' => 'site']) }}" class="font-medium underline underline-offset-2">{{ __('hata::content.public.home.settings') }}</a>
    </x-hata::site.strip>
@endif
