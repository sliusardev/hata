<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'hata::settings.profile')->name('profile.edit');

    Route::livewire('settings/language', 'hata::settings.language')->name('language.edit');

    Route::livewire('settings/appearance', 'hata::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'hata::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
