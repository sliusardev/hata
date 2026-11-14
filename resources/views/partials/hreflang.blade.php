{{-- Alternate addresses of the current public page, one per active content language, for search engines. --}}
@php
    $contentLocales = app(App\Support\Localization\ContentLocales::class);
    $alternates = $contentLocales->alternates();
@endphp

@if (count($alternates) > 1)
    @foreach ($alternates as $code => $url)
        <link rel="alternate" hreflang="{{ $contentLocales->language($code)->hreflang() }}" href="{{ $url }}" />
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $alternates[$contentLocales->defaultCode()] }}" />
@endif
