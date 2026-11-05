<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

/*
| Fortify's "home" path. Sends users with admin access to the admin panel and
| everyone else to their account settings, so nobody lands on a 403 page.
*/
Route::get('dashboard', function (Request $request) {
    return $request->user()->can('admin.access')
        ? redirect()->route('admin.dashboard')
        : redirect()->route('profile.edit');
})->middleware('auth')->name('dashboard');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
