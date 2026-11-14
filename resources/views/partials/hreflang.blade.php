{{--
    Alternate addresses of the current public page for search engines: one per active content language, or the
    public translations a content record handed over (ContentLocales::useAlternates()). x-default is the default
    language's address, left out when the page has none.
--}}
@php
    $contentLocales = app(App\Support\Localization\ContentLocales::class);
    $alternates = $contentLocales->alternates();
@endphp

@if (count($alternates) > 1)
    @foreach ($alternates as $code => $url)
        <link rel="alternate" hreflang="{{ $contentLocales->language($code)->hreflang() }}" href="{{ $url }}" />
    @endforeach
    @isset($alternates[$contentLocales->defaultCode()])
        <link rel="alternate" hreflang="x-default" href="{{ $alternates[$contentLocales->defaultCode()] }}" />
    @endisset
@endif
