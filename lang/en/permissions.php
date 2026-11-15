<?php

return [

    'groups' => [
        'admin' => 'Admin panel',
        'users' => 'Users',
        'roles' => 'Roles',
        'media' => 'Media library',
        'settings' => 'Site settings',
        'maintenance' => 'Maintenance',
        'pages' => 'Pages',
        'posts' => 'Posts',
    ],

    'items' => [
        'admin' => [
            'access' => 'Access the admin panel',
        ],
        'users' => [
            'view' => 'View users',
            'create' => 'Create users',
            'edit' => 'Edit users and their roles',
            'delete' => 'Delete users',
        ],
        'roles' => [
            'view' => 'View roles and their permissions',
            'create' => 'Create roles',
            'edit' => 'Edit roles and their permissions',
            'delete' => 'Delete roles',
        ],
        'media' => [
            'view' => 'View the media library',
            'upload' => 'Upload files',
            'edit' => 'Edit files and folders',
            'delete' => 'Delete files and folders',
        ],
        'settings' => [
            'edit' => 'Editing site settings',
        ],
        'maintenance' => [
            'run' => 'Run migrations, seeders and cache commands',
        ],
        'pages' => [
            'view' => 'View pages',
            'create' => 'Create pages',
            'edit' => 'Edit and publish pages',
            'delete' => 'Move pages to the trash and delete them',
        ],
        'posts' => [
            'view' => 'View posts',
            'create' => 'Create posts',
            'edit' => 'Edit and publish posts',
            'delete' => 'Move posts to the trash and delete them',
        ],
    ],

];
