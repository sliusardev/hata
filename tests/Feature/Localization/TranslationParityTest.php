<?php

use Hata\Hata;
use Hata\Support\Translation\VendorTranslations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Load every lang/{locale}/*.php file as a flat map of "file.dot.key" => value.
 *
 * @return array<string, mixed>
 */
function flattenedTranslations(string $locale, string $root = 'resources/lang'): array
{
    $lines = [];

    foreach (File::files(Hata::path("{$root}/{$locale}")) as $file) {
        $group = $file->getFilenameWithoutExtension();

        foreach (Arr::dot(require $file->getPathname()) as $key => $value) {
            $lines["{$group}.{$key}"] = $value;
        }
    }

    ksort($lines);

    return $lines;
}

/**
 * Every configured locale except the English source.
 *
 * @return list<string>
 */
function translatedLocales(): array
{
    return array_values(array_diff(array_keys(config('hata.admin.interface_locales')), ['en']));
}

/**
 * @return list<string>
 */
function translationFiles(string $locale, string $root = 'resources/lang'): array
{
    return collect(File::files(Hata::path("{$root}/{$locale}")))
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function placeholdersIn(string $line): array
{
    preg_match_all('/:([a-z_]+)/i', $line, $matches);

    return collect($matches[1])->map(fn (string $name): string => strtolower($name))->unique()->sort()->values()->all();
}

test('no locale uses JSON translation files', function () {
    expect(File::glob(Hata::path('resources/lang/*.json')))->toBeEmpty();
});

test('every available locale has the same translation files as English', function () {
    foreach (translatedLocales() as $locale) {
        expect(translationFiles($locale))->toBe(translationFiles('en'), "Files differ in {$locale}");
    }
});

test('every available locale has the same keys as English', function () {
    $english = array_keys(flattenedTranslations('en'));

    foreach (translatedLocales() as $locale) {
        $translated = array_keys(flattenedTranslations($locale));

        expect(array_values(array_diff($english, $translated)))->toBe([], "Missing in {$locale}")
            ->and(array_values(array_diff($translated, $english)))->toBe([], "Extra in {$locale}");
    }
});

test('no translation value is empty', function () {
    foreach (array_keys(config('hata.admin.interface_locales')) as $locale) {
        $empty = collect(flattenedTranslations($locale))
            ->filter(fn (mixed $value): bool => $value !== [] && (! is_string($value) || trim($value) === ''))
            ->keys()
            ->all();

        expect($empty)->toBe([], "Empty values in {$locale}");
    }
});

test('every translation uses the same placeholders as English', function () {
    $english = flattenedTranslations('en');

    foreach (translatedLocales() as $locale) {
        $mismatched = collect(flattenedTranslations($locale))
            ->filter(fn (mixed $value, string $key): bool => is_string($value)
                && isset($english[$key])
                && placeholdersIn($value) !== placeholdersIn($english[$key]))
            ->keys()
            ->all();

        expect($mismatched)->toBe([], "Placeholder mismatch in {$locale}");
    }
});

test('every translation key used in the code exists', function () {
    $english = flattenedTranslations('en');

    $files = collect(File::allFiles(Hata::path('resources/views')))
        ->merge(File::allFiles(Hata::path('src')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

    $missing = $files
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/(?:__|trans|trans_choice)\\(\\s*'hata::([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", File::get($file->getPathname()), $matches);
            preg_match_all("/#\\[Title\\('hata::([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\)\\]/", File::get($file->getPathname()), $titles);

            return array_merge($matches[1], $titles[1]);
        })
        ->unique()
        ->reject(fn (string $key): bool => array_key_exists($key, $english)
            || collect($english)->keys()->contains(fn (string $line): bool => str_starts_with($line, "{$key}.")))
        ->values()
        ->all();

    expect($missing)->toBe([]);
});

test('every vendor literal is mapped to an existing translation key', function () {
    $english = flattenedTranslations('en');

    $missing = array_values(array_filter(
        VendorTranslations::KEYS,
        fn (string $key): bool => ! array_key_exists(preg_replace('/^hata::/', '', $key), $english),
    ));

    expect($missing)->toBe([]);
});

test('no Hata translation key is used without the hata:: namespace', function () {
    $groups = collect(translationFiles('en'))->map(fn (string $file): string => basename($file, '.php'))->implode('|');

    $files = collect(File::allFiles(Hata::path('resources/views')))
        ->merge(File::allFiles(Hata::path('src')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

    $bare = $files
        ->filter(fn (SplFileInfo $file): bool => preg_match("/(?:__|trans|trans_choice)\\(\\s*['\"](?:{$groups})\\.[a-z0-9_]/", File::get($file->getPathname())) === 1
            || preg_match("/#\\[Title\\('(?:{$groups})\\./", File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($bare)->toBe([]);
});

/**
 * Laravel's own English for the framework groups, with Hata's additions (the form field names in
 * `validation.attributes`) on top: the source the other languages of `resources/lang-framework` follow.
 *
 * @return array<string, mixed>
 */
function frameworkEnglish(): array
{
    $lines = [];

    foreach (File::files(base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en')) as $file) {
        foreach (Arr::dot(require $file->getPathname()) as $key => $value) {
            $lines["{$file->getFilenameWithoutExtension()}.{$key}"] = $value;
        }
    }

    // The placeholders Laravel leaves for a site to fill in are not lines to translate.
    unset($lines['validation.custom.attribute-name.rule-name'], $lines['validation.attributes']);

    return [...$lines, ...flattenedTranslations('en', 'resources/lang-framework')];
}

test('every language of lang-framework has the framework groups and the keys of Laravel\'s English', function () {
    $english = array_keys(frameworkEnglish());
    $groups = array_map(fn (SplFileInfo $file): string => $file->getFilename(), File::files(base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en')));
    sort($groups);

    foreach (translatedLocales() as $locale) {
        $translated = array_values(array_diff(array_keys(flattenedTranslations($locale, 'resources/lang-framework')), ['validation.custom']));

        expect(translationFiles($locale, 'resources/lang-framework'))->toBe($groups, "Files differ in {$locale}")
            ->and(array_values(array_diff($english, $translated)))->toBe([], "Missing in {$locale}")
            ->and(array_values(array_diff($translated, $english)))->toBe([], "Extra in {$locale}");
    }
});

test('lang-framework has no empty value and keeps the placeholders of the English', function () {
    $english = frameworkEnglish();

    foreach (array_keys(config('hata.admin.interface_locales')) as $locale) {
        $lines = flattenedTranslations($locale, 'resources/lang-framework');

        $empty = collect($lines)->filter(fn (mixed $value): bool => $value !== [] && (! is_string($value) || trim($value) === ''))->keys()->all();
        $mismatched = collect($lines)
            ->filter(fn (mixed $value, string $key): bool => $locale !== 'en' && is_string($value) && isset($english[$key]) && is_string($english[$key]) && placeholdersIn($value) !== placeholdersIn($english[$key]))
            ->keys()
            ->all();

        expect($empty)->toBe([], "Empty values in {$locale}")->and($mismatched)->toBe([], "Placeholder mismatch in {$locale}");
    }
});
