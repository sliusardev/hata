<?php

use App\Models\User;

beforeEach(fn () => seedRolesAndPermissions());

test('super admins pass every permission check without explicit permissions', function () {
    $superAdmin = User::factory()->withRole('super-admin')->create();

    expect($superAdmin->getAllPermissions())->toBeEmpty()
        ->and($superAdmin->can('users.delete'))->toBeTrue()
        ->and($superAdmin->can('permission.that.does.not.exist'))->toBeTrue();
});

test('other users only pass checks for permissions they have', function () {
    $editor = User::factory()->withRole('editor')->create();

    expect($editor->can('admin.access'))->toBeTrue()
        ->and($editor->can('users.view'))->toBeFalse();
});
