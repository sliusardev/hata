<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Registration
    |--------------------------------------------------------------------------
    |
    | When enabled, visitors can create an account. New accounts receive the
    | default role below (none by default). Values stored in the site settings
    | (admin panel) override the ones from .env.
    |
    */

    'registration_enabled' => (bool) env('ADMIN_REGISTRATION_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Default Role Of Registered Users
    |--------------------------------------------------------------------------
    |
    | Name of the role given to users who register themselves. Null means no
    | role. Like the options above, it can be changed in the site settings.
    |
    */

    'default_role' => env('ADMIN_DEFAULT_ROLE'),

    /*
    |--------------------------------------------------------------------------
    | Display Timezone
    |--------------------------------------------------------------------------
    |
    | Dates are stored in app.timezone (UTC) and shown in this timezone.
    |
    */

    'timezone' => env('ADMIN_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Color Scheme
    |--------------------------------------------------------------------------
    |
    | Colors of the admin panel and the sign-in pages, set in the site settings.
    | See Hata\Support\ColorScheme\ColorScheme.
    |
    */

    'colors' => [
        // A ColorScheme::PRESETS key or "custom" (uses accent and gray below).
        'scheme' => env('ADMIN_COLOR_SCHEME', 'neutral'),
        'accent' => null,
        'gray' => 'neutral',
        'tint_sidebar' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Site Logo
    |--------------------------------------------------------------------------
    |
    | Ids of the media library files chosen as the light and dark theme logos
    | in the site settings. Null means the default Hata mark.
    |
    */

    'logo' => [
        'light' => null,
        'dark' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Initial Super Admin
    |--------------------------------------------------------------------------
    |
    | Used by the DatabaseSeeder to create the first super admin on hosting
    | without SSH access. Leave ADMIN_EMAIL empty to skip it, and remove the
    | password from the environment after the first deploy.
    |
    */

    'initial_admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

];
