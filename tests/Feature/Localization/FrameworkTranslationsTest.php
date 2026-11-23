<?php

use Illuminate\Support\Facades\Lang;
use Illuminate\Translation\FileLoader;

function translatorWithSiteLangPath(string $path): void
{
    app()->useLangPath($path);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
    Lang::clearResolvedInstances();
}

it('lets the site\'s own lang file win over Hata, and Hata win over Laravel', function () {
    translatorWithSiteLangPath(dirname(__DIR__, 2).'/fixtures/lang');

    expect(app('translation.loader'))->toBeInstanceOf(FileLoader::class)
        ->and(__('validation.required', [], 'uk'))->toBe('X')
        ->and(__('validation.email', ['attribute' => 'a'], 'uk'))->toBe('Поле a має бути дійсною адресою електронної пошти.')
        ->and(__('auth.failed', [], 'uk'))->toBe('Ці облікові дані не збігаються з нашими записами.');
});

it('keeps Laravel\'s own English for the framework groups', function () {
    expect(__('validation.required', ['attribute' => 'name'], 'en'))->toBe('The name field is required.')
        ->and(__('auth.failed', [], 'en'))->toBe('These credentials do not match our records.');
});

it('returns Hata\'s Ukrainian validation message for a form field', function () {
    app()->setLocale('uk');

    $validator = validator(['name' => ''], ['name' => 'required']);

    expect($validator->errors()->first('name'))->toContain('обов’язкове');
});
