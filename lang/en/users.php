<?php

return [

    'count' => ':count user|:count users',

    'index' => [
        'title' => 'Users',
        'description' => 'Manage the people who can sign in and their roles',
        'create' => 'Add user',
        'search' => 'Search by name or email',
        'all_roles' => 'All roles',
        'filter_by_role' => 'Filter by role',
        'columns' => [
            'user' => 'User',
            'email' => 'Email',
            'roles' => 'Roles',
            'locale' => 'Language',
            'created_at' => 'Created',
            'actions' => 'Actions',
        ],
        'no_roles' => 'No roles',
        'you' => 'You',
        'empty' => [
            'title' => 'No users yet',
            'description' => 'Add the first user to get started.',
        ],
        'no_results' => [
            'title' => 'No users found',
            'description' => 'Try a different search or role filter.',
        ],
    ],

    'create' => [
        'title' => 'Add user',
        'description' => 'Create an account and choose its roles',
        'submit' => 'Create user',
    ],

    'edit' => [
        'title' => 'Edit user',
        'description' => 'Update the account details and roles',
        'submit' => 'Save changes',
    ],

    'form' => [
        'account' => 'Account',
        'name' => 'Name',
        'email' => 'Email',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'password_keep' => 'Leave blank to keep the current password.',
        'locale' => 'Interface language',
        'roles' => 'Roles',
        'roles_description' => 'Roles define what the user can do in the admin panel.',
        'role_unavailable' => 'You cannot assign or remove this role.',
        'own_roles_locked' => 'You cannot change your own roles.',
        'no_roles_available' => 'There are no roles yet.',
        'email_verified' => 'Email verified',
        'email_verified_description' => 'Mark the email address as verified.',
        'verified' => 'Verified',
        'not_verified' => 'Not verified',
    ],

    'delete' => [
        'title' => 'Delete user?',
        'confirm' => 'The user ":name" will be permanently deleted. This cannot be undone.',
        'submit' => 'Delete user',
    ],

    'messages' => [
        'created' => 'User created.',
        'updated' => 'User updated.',
        'deleted' => 'User deleted.',
    ],

    'errors' => [
        'cannot_delete_self' => 'You cannot delete your own account here.',
        'cannot_delete_last_super_admin' => 'The last super admin cannot be deleted.',
        'cannot_remove_last_super_admin' => 'The super admin role cannot be removed from the last super admin.',
        'cannot_manage_super_admin' => 'Only a super admin can manage super admins.',
        'cannot_change_own_roles' => 'You cannot change your own roles.',
        'cannot_assign_role' => 'You cannot assign the role ":role" because it has permissions you do not have.',
    ],

];
