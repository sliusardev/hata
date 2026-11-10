<?php

/*
|--------------------------------------------------------------------------
| Permission Registry
|--------------------------------------------------------------------------
|
| Permissions are declared in code and synced to the database with
| `php artisan permissions:sync`. Administrators can only assign them to
| roles. Names follow the "resource.action" format. Interface labels live
| in lang/{locale}/permissions.php (groups.<resource>, items.<resource>.<action>).
|
| Modules and themes can add their own groups from a service provider:
|
|     $this->app->make(PermissionRegistry::class)->register('pages', ['view', 'edit']);
|
*/

return [

    'groups' => [
        'admin' => ['access'],
        'users' => ['view', 'create', 'edit', 'delete'],
        'roles' => ['view', 'create', 'edit', 'delete'],
        'media' => ['view', 'upload', 'edit', 'delete'],
        'settings' => ['edit'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Permissions Of System Roles
    |--------------------------------------------------------------------------
    |
    | Granted by the RoleSeeder. The seeder only adds permissions, it never
    | removes ones an administrator assigned. "*" means every registered
    | permission. super-admin is not listed: it passes every check through
    | Gate::before.
    |
    */

    'roles' => [
        'admin' => ['*'],
        'editor' => ['admin.access', 'media.view', 'media.upload', 'media.edit', 'media.delete'],
        'author' => ['admin.access'],
    ],

];
