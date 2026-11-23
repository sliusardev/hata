<?php

return [

    'names' => [
        'super-admin' => 'Super admin',
        'admin' => 'Administrator',
        'editor' => 'Editor',
        'author' => 'Author',
    ],

    'permissions_count' => ':count permission|:count permissions',
    'all_permissions' => 'All',
    'system' => 'System',

    'index' => [
        'title' => 'Roles',
        'description' => 'Group permissions into roles and assign them to users',
        'create' => 'Add role',
        'columns' => [
            'name' => 'Name',
            'technical_name' => 'Technical name',
            'users' => 'Users',
            'permissions' => 'Permissions',
            'actions' => 'Actions',
        ],
        'empty' => [
            'title' => 'No roles yet',
            'description' => 'Run the database seeder to create the system roles.',
        ],
    ],

    'create' => [
        'title' => 'Add role',
        'description' => 'Name the role and choose its permissions',
        'submit' => 'Create role',
    ],

    'edit' => [
        'title' => 'Edit role',
        'view_title' => 'View role',
        'submit' => 'Save changes',
        'read_only' => 'You can view this role but not change it.',
    ],

    'form' => [
        'details' => 'Details',
        'label' => 'Name',
        'name' => 'Technical name',
        'name_description' => 'Lowercase letters, digits and dashes. It cannot be changed later.',
        'system_name' => 'This is a system role. Its name cannot be changed.',
        'permissions' => 'Permissions',
        'permissions_description' => 'Choose what users with this role can do.',
        'select_all' => 'Select all',
        'clear_all' => 'Clear all',
        'permission_unavailable' => 'You do not have this permission, so you cannot grant it.',
        'super_admin_heading' => 'This role has every permission',
        'super_admin_description' => 'Super admins pass every permission check, including permissions added later. The role cannot be changed.',
    ],

    'delete' => [
        'title' => 'Delete role?',
        'confirm' => 'The role ":name" will be permanently deleted. This cannot be undone.',
        'in_use' => 'The role ":name" cannot be deleted because it is assigned to users (:users). Remove it from them first.',
        'submit' => 'Delete role',
    ],

    'messages' => [
        'created' => 'Role created.',
        'updated' => 'Role updated.',
        'deleted' => 'Role deleted.',
    ],

    'errors' => [
        'super_admin_locked' => 'The super admin role cannot be changed or deleted.',
        'cannot_delete_system' => 'System roles cannot be deleted.',
        'cannot_delete_in_use' => 'This role cannot be deleted because it is assigned to users (:users).',
        'cannot_grant_permission' => 'You cannot grant the permission ":permission" because you do not have it.',
    ],

];
