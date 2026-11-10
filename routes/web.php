<?php

use App\Http\Controllers\MediaController;
use App\Http\Controllers\SiteLogoController;
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

/*
| The logos uploaded in the site settings. Public, because the sign-in pages show them too.
*/
Route::get('branding/logo/{variant}', SiteLogoController::class)
    ->whereIn('variant', ['light', 'dark'])
    ->name('branding.logo');

/*
| Public media library files. No auth: the URLs are embedded in site content.
*/
Route::get('media/{key}/{name}', MediaController::class)
    ->where('key', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
    ->where('name', '[^/]+')
    ->name('media.show');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
