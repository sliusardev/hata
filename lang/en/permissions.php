<?php

return [

    'groups' => [
        'admin' => 'Admin panel',
        'users' => 'Users',
        'roles' => 'Roles',
        'settings' => 'Site settings',
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
        'settings' => [
            'edit' => 'Editing site settings',
        ],
    ],

];
