<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Registration
    |--------------------------------------------------------------------------
    |
    | When enabled, visitors can create an account. New accounts receive no
    | role and therefore have no access to the admin panel.
    |
    */

    'registration_enabled' => (bool) env('ADMIN_REGISTRATION_ENABLED', false),

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
