<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
});

test('users without admin access cannot open the admin panel', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.dashboard'))->assertForbidden();
});

test('users with admin access can open the admin panel', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.dashboard'))->assertOk();
});

test('after login users with admin access land in the admin panel', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));
});

test('after login users without admin access land on their profile settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('profile.edit'));
});

test('the admin navigation is hidden from users without admin access', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee(route('admin.dashboard'));
});
