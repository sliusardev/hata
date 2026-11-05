<?php

use App\Support\Translation\VendorTranslations;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Load every lang/{locale}/*.php file as a flat map of "file.dot.key" => value.
 *
 * @return array<string, mixed>
 */
function flattenedTranslations(string $locale): array
{
    $lines = [];

    foreach (File::files(lang_path($locale)) as $file) {
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
    return array_values(array_diff(array_keys(config('app.available_locales')), ['en']));
}

/**
 * @return list<string>
 */
function translationFiles(string $locale): array
{
    return collect(File::files(lang_path($locale)))
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
    expect(File::glob(lang_path('*.json')))->toBeEmpty();
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
    foreach (array_keys(config('app.available_locales')) as $locale) {
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

    $files = collect(File::allFiles(resource_path('views')))
        ->merge(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

    $missing = $files
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/(?:__|trans|trans_choice)\\(\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", File::get($file->getPathname()), $matches);
            preg_match_all("/#\\[Title\\('([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\)\\]/", File::get($file->getPathname()), $titles);

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
        fn (string $key): bool => ! array_key_exists($key, $english),
    ));

    expect($missing)->toBe([]);
});
