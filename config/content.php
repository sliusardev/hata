<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content languages
    |--------------------------------------------------------------------------
    |
    | The languages of the site's content, chosen in Site settings → Languages
    | and stored as site settings (`content_locales`, `content_default_locale`).
    | With nothing stored the site has one language, the default interface
    | language. `locales` is a list of ['code' => string, 'active' => bool].
    |
    | `interface_default` is written when the site settings are applied: the
    | site's default interface language. Read it instead of `app.locale`, which
    | holds the current request's language once the middleware has run.
    |
    */

    'locales' => null,

    'default_locale' => null,

    'interface_default' => null,

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | Names content may not use as the first segment of its address, besides
    | every language code and the first segment of every service route (see
    | App\Support\Localization\ReservedSlugs): what the web server answers
    | itself from the public folder.
    |
    */

    'reserved_slugs' => [
        'storage', 'build', 'vendor', 'index.php', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png',
        'robots.txt', 'sitemap.xml', '.well-known',
    ],

];
