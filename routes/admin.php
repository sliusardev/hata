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
    });
