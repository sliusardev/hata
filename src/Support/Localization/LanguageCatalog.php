<?php

namespace Hata\Support\Localization;

/**
 * The built-in list of languages a site's content can be in. Code, not data: the route pattern is built from
 * it, so routes never depend on the database.
 */
class LanguageCatalog
{
    /**
     * @return array<string, Language>
     */
    public function all(): array
    {
        $languages = [];

        foreach ((array) config('languages') as $code => $entry) {
            $languages[(string) $code] = new Language((string) $code, (string) $entry['name'], (string) $entry['native'], (bool) ($entry['rtl'] ?? false));
        }

        return $languages;
    }

    public function has(string $code): bool
    {
        return array_key_exists($code, (array) config('languages'));
    }

    public function find(string $code): ?Language
    {
        $entry = config('languages')[$code] ?? null;

        return $entry === null ? null : new Language($code, (string) $entry['name'], (string) $entry['native'], (bool) ($entry['rtl'] ?? false));
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(strval(...), array_keys((array) config('languages')));
    }

    /**
     * The route pattern of the `{locale}` prefix: any catalogue code, and nothing else.
     */
    public function pattern(): string
    {
        return implode('|', array_map(fn (string $code): string => preg_quote($code, '#'), $this->codes()));
    }
}
