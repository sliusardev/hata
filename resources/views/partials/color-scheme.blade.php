{{-- The color scheme from the site settings. Values are generated hex colors, never user text. --}}
@php($colorSchemeCss = \App\Support\ColorScheme\ColorScheme::current()->css())
@if ($colorSchemeCss !== '')
    <style id="color-scheme">{!! $colorSchemeCss !!}</style>
@endif
