<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

@include('hata::partials.favicons')

@hataFonts

@hataAssets(['resources/css/app.css', 'resources/js/app.js'])
{{ app(Hata\Support\Admin\AdminAssets::class)->tags() }}
@include('hata::partials.color-scheme')
@fluxAppearance
