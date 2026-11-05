<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users with admin access can visit the dashboard', function () {
    $user = User::factory()->withPermissions(['admin.access'])->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertOk();
});

test('users with an unverified email can visit the dashboard while email verification is disabled', function () {
    $user = User::factory()->unverified()->withPermissions(['admin.access'])->create();
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertOk();
});
