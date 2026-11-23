<?php

use Hata\Http\Controllers\ContentController;
use Hata\Http\Controllers\MediaController;
use Hata\Http\Controllers\StaticMediaFallbackController;
use Hata\Http\Middleware\LookUpContent;
use Hata\Support\Content\ContentRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Public pages, in every content language: the default one at these addresses, the others under /<code>/.
*/
Route::localized(function () {
    Route::view('/', 'hata::welcome')->name('home');
});

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
| Public media library files. No auth: the URLs are embedded in site content.
| No "web" middleware either: a page may request dozens of files, and none of them
| needs a session, cookies, CSRF or a locale.
*/
Route::get('media/{key}/{name}', MediaController::class)
    ->where('key', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}')
    ->where('name', '[^/]+')
    ->withoutMiddleware('web')
    ->name('media.show');

/*
| The static address of a locally stored image, for when the `public/storage` link is missing and the web server
| does not answer it itself. It must not depend on the link's state.
*/
Route::get('storage/media/{path}', StaticMediaFallbackController::class)
    ->where('path', '.+')
    ->withoutMiddleware('web')
    ->name('media.static');

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';

/*
| Content records (pages, later posts) at their public addresses. A fallback in both copies, so every other route
| (admin, Fortify, media, Livewire, the home page) wins; ContentRouter resolves the path from code-defined bases.
| Only slug-shaped paths match, so anything else (`/favicon.ico`, `/.env`) stays the router's 404, with no session
| and no query. LookUpContent (ordered before the session) answers a slug-shaped address with nothing at it with 404,
| also without a session.
*/
Route::localized(function () {
    Route::get('{path}', ContentController::class)
        ->where('path', ContentRouter::PATH_PATTERN)
        ->middleware(LookUpContent::class)
        ->fallback()
        ->name('content');
});
