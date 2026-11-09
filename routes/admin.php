<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Prefixed with /admin, named admin.*, and closed to users without the
| admin.access permission. Each page also authorizes its own permission.
|
*/

Route::middleware(['auth', 'verified', 'can:admin.access'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::view('/', 'dashboard')->name('dashboard');

        Route::livewire('users', 'pages::admin.users.index')->name('users.index');
        Route::livewire('users/create', 'pages::admin.users.create')->name('users.create');
        Route::livewire('users/{user}/edit', 'pages::admin.users.edit')->name('users.edit');

        Route::livewire('roles', 'pages::admin.roles.index')->name('roles.index');
        Route::livewire('roles/create', 'pages::admin.roles.create')->name('roles.create');
        Route::livewire('roles/{role}/edit', 'pages::admin.roles.edit')->name('roles.edit');

        Route::livewire('settings', 'pages::admin.settings.edit')->name('settings.edit');
    });
