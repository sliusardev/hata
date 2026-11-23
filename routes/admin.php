<?php

use Hata\Http\Controllers\Admin\MaintenanceLogChunkController;
use Hata\Http\Controllers\Admin\MaintenanceRunController;
use Hata\Http\Controllers\Admin\MediaDirectCheckController;
use Hata\Http\Controllers\MediaSourcePreviewController;
use Hata\Http\Controllers\MediaZipController;
use Hata\Support\Admin\AdminPath;
use Hata\Support\Content\ContentTypes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Prefixed with the admin path (hata.admin.path, `admin` by default), named admin.*, and closed to users without the
| admin.access permission. Each page also authorizes its own permission.
|
*/

Route::middleware(['auth', 'verified', 'can:admin.access'])
    ->prefix(AdminPath::resolve())
    ->name('admin.')
    ->group(function () {
        Route::view('/', 'hata::dashboard')->name('dashboard');

        $contentTypes = app(ContentTypes::class)->pattern();
        Route::livewire('content/{type}', 'hata::admin.content.index')->where('type', $contentTypes)->name('content.index');
        Route::livewire('content/{type}/create', 'hata::admin.content.create')->where('type', $contentTypes)->name('content.create');
        Route::livewire('content/{type}/{entry}/edit', 'hata::admin.content.edit')->where('type', $contentTypes)->whereNumber('entry')->withTrashed()->name('content.edit');

        Route::livewire('media', 'hata::admin.media.index')->name('media.index');
        Route::get('media/zip', MediaZipController::class)->middleware('can:media.view')->name('media.zip');
        Route::livewire('media/optimize', 'hata::admin.media.optimize')->middleware('can:media.edit')->name('media.optimize');
        Route::livewire('media/move', 'hata::admin.media.move')->middleware('can:media.edit')->name('media.move');
        Route::post('media/direct-check', MediaDirectCheckController::class)->middleware('can:settings.edit')->name('media.direct-check');
        Route::livewire('media/{media}/crop', 'hata::admin.media.crop')->middleware('can:update,media')->name('media.crop');
        Route::get('media/{media}/source', MediaSourcePreviewController::class)->middleware('can:update,media')->name('media.source');

        Route::livewire('users', 'hata::admin.users.index')->name('users.index');
        Route::livewire('users/create', 'hata::admin.users.create')->name('users.create');
        Route::livewire('users/{user}/edit', 'hata::admin.users.edit')->name('users.edit');

        Route::livewire('roles', 'hata::admin.roles.index')->name('roles.index');
        Route::livewire('roles/create', 'hata::admin.roles.create')->name('roles.create');
        Route::livewire('roles/{role}/edit', 'hata::admin.roles.edit')->name('roles.edit');

        Route::livewire('settings', 'hata::admin.settings.edit')->name('settings.edit');
        Route::livewire('maintenance', 'hata::admin.maintenance.index')->middleware('can:maintenance.run')->name('maintenance');
        Route::post('maintenance/run', MaintenanceRunController::class)->middleware('can:maintenance.run')->name('maintenance.run');
        Route::get('maintenance/logs/{key}', MaintenanceLogChunkController::class)->where('key', '[a-f0-9]{32}')->middleware('can:maintenance.run')->name('maintenance.logs.chunk');
    });
