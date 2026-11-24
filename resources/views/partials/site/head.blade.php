{{--
    The head of a public page, printed by @hataHead from the request's PageMeta: title, description, robots,
    canonical, Open Graph and Twitter tags, then the hreflang links. Nothing is printed for an empty value; a
    preview (noindex) carries no canonical and no Open Graph.
--}}
@php
    $meta = app(Hata\Support\Seo\PageMeta::class);
    $contentLocales = app(Hata\Support\Localization\ContentLocales::class);
    $description = $meta->description();
    $canonical = $meta->canonical();
@endphp
<title>{{ $meta->title() }}</title>
@if ($description !== null)
    <meta name="description" content="{{ $description }}">
@endif
@if ($meta->isNoindex())
    <meta name="robots" content="noindex">
@endif
@if ($canonical !== null)
    <link rel="canonical" href="{{ $canonical }}">
@endif
@unless ($meta->isNoindex())
    @php
        $image = $meta->image();
        $current = $contentLocales->current();
    @endphp
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $meta->ogTitle() }}">
    @if ($description !== null)
        <meta property="og:description" content="{{ $description }}">
    @endif
    @if ($canonical !== null)
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    <meta property="og:type" content="{{ $meta->type() }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', $current->hreflang()) }}">
    @foreach ($contentLocales->alternates() as $code => $url)
        @if ($code !== $current->code)
            <meta property="og:locale:alternate" content="{{ str_replace('-', '_', $contentLocales->language($code)->hreflang()) }}">
        @endif
    @endforeach
    @if ($image !== null)
        <meta property="og:image" content="{{ url($image->url()) }}">
        @if ($image->width)
            <meta property="og:image:width" content="{{ $image->width }}">
        @endif
        @if ($image->height)
            <meta property="og:image:height" content="{{ $image->height }}">
        @endif
        @if (($alt = $image->altFor($current->code)) !== '')
            <meta property="og:image:alt" content="{{ $alt }}">
        @endif
    @endif
    <meta name="twitter:card" content="{{ $image !== null ? 'summary_large_image' : 'summary' }}">
@endunless
@include('hata::partials.hreflang')
