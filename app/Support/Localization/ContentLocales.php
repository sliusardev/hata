<?php

namespace App\Support\Localization;

use Illuminate\Support\Facades\Route;

/**
 * The languages of the site's content: which are added, which are on, which is the default, and which one the
 * current request is in.
 *
 * Reads config only (`content.*`, written from the site settings), so a public request costs no query. The
 * default language is always in the list, on, and first; a broken stored value falls back to a working one
 * instead of breaking the site.
 */
class ContentLocales
{
    /**
     * The prefix of the route names registered under `{locale}` by Route::localized().
     */
    public const string ROUTE_PREFIX = 'localized.';

    /**
     * The session key that remembers the language of the site a visitor last saw.
     */
    public const string SESSION_KEY = 'site_locale';

    private ?string $current = null;

    /**
     * The localized route this request matched, without the name prefix and the locale parameter.
     *
     * @var array{name: string, parameters: array<string, mixed>}|null
     */
    private ?array $currentRoute = null;

    /**
     * The alternates the current page handed over itself (a content record), or null to build them from the route.
     *
     * @var array<string, string>|null
     */
    private ?array $alternates = null;

    public function __construct(private readonly LanguageCatalog $catalog) {}

    /**
     * The added languages as stored, made valid: the default first, then in the order added.
     *
     * @return list<array{code: string, active: bool}>
     */
    public function entries(): array
    {
        $entries = [];

        foreach ((array) config('content.locales') as $entry) {
            $code = is_array($entry) ? ($entry['code'] ?? null) : null;

            if (! is_string($code) || isset($entries[$code]) || ! $this->catalog->has($code)) {
                continue;
            }

            $entries[$code] = ['code' => $code, 'active' => (bool) ($entry['active'] ?? false)];
        }

        $default = config('content.default_locale');

        if (! is_string($default) || ! isset($entries[$default])) {
            $default = match (true) {
                isset($entries[$this->interfaceDefault()]) => $this->interfaceDefault(),
                $entries !== [] => array_key_first($entries),
                is_string($default) && $this->catalog->has($default) => $default,
                default => $this->interfaceDefault(),
            };
        }

        unset($entries[$default]);

        return [['code' => $default, 'active' => true], ...array_values($entries)];
    }

    /**
     * @return list<Language>
     */
    public function all(): array
    {
        return array_map(fn (array $entry): Language => $this->language($entry['code']), $this->entries());
    }

    /**
     * @return list<Language>
     */
    public function active(): array
    {
        return array_values(array_map(
            fn (array $entry): Language => $this->language($entry['code']),
            array_filter($this->entries(), fn (array $entry): bool => $entry['active']),
        ));
    }

    public function default(): Language
    {
        return $this->language($this->defaultCode());
    }

    public function defaultCode(): string
    {
        return $this->entries()[0]['code'];
    }

    public function has(string $code): bool
    {
        return array_any($this->entries(), fn (array $entry): bool => $entry['code'] === $code);
    }

    public function isActive(string $code): bool
    {
        return array_any($this->entries(), fn (array $entry): bool => $entry['code'] === $code && $entry['active']);
    }

    /**
     * The language of a public address from its `{locale}` route parameter: the prefix, or the default language
     * when the address has none. An address whose language is not active answers 404 (isActive()). Shared by
     * SetContentLocale and LookUpContent, so both read an address the same way.
     */
    public function codeForPrefix(mixed $prefix): string
    {
        return is_string($prefix) ? $prefix : $this->defaultCode();
    }

    /**
     * Whether a public address carries the default language's own prefix, which answers 301 to the address
     * without it (SetContentLocale).
     *
     * @phpstan-assert-if-true string $prefix
     */
    public function isDefaultPrefix(mixed $prefix): bool
    {
        return is_string($prefix) && $prefix === $this->defaultCode();
    }

    /**
     * The site's default interface language: what guests and users without a language of their own see in
     * the admin and on the sign-in pages. Not `app.locale`, which holds the current request's language once
     * the middleware has run.
     */
    public function interfaceDefault(): string
    {
        return (string) (config('content.interface_default') ?? config('app.locale'));
    }

    /**
     * The content language of this request: the one in the address on a public page, the default elsewhere.
     */
    public function current(): Language
    {
        return $this->language($this->current ?? $this->defaultCode());
    }

    /**
     * @param  array{name: string, parameters: array<string, mixed>}|null  $route
     */
    public function setCurrent(string $code, ?array $route = null): void
    {
        $this->current = $code;
        $this->currentRoute = $route;
        // A new request starts without the alternates a previous page handed over.
        $this->alternates = null;
    }

    /**
     * The address of a localized route in a language (the current one when null): the plain route for the
     * default language, the prefixed copy for the others.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $name, array $parameters = [], ?string $locale = null, bool $absolute = true): string
    {
        $locale ??= $this->current()->code;

        return $locale === $this->defaultCode()
            ? route($name, $parameters, $absolute)
            : route(self::ROUTE_PREFIX.$name, ['locale' => $locale, ...$parameters], $absolute);
    }

    /**
     * The current page in every active language, for a language switcher and `hreflang`. Empty outside a
     * localized route. A page whose address differs per language (a content record) hands its own over with
     * useAlternates().
     *
     * @return array<string, string> code => address
     */
    public function alternates(): array
    {
        if ($this->alternates !== null) {
            return $this->alternates;
        }

        if ($this->currentRoute === null || ! Route::has(self::ROUTE_PREFIX.$this->currentRoute['name'])) {
            return [];
        }

        $alternates = [];

        foreach ($this->active() as $language) {
            $alternates[$language->code] = $this->route($this->currentRoute['name'], $this->currentRoute['parameters'], $language->code);
        }

        return $alternates;
    }

    /**
     * Use exactly these addresses as the current page's alternates (code => address), in place of the current route
     * rebuilt in every active language. Reset when the next request sets the current language.
     *
     * @param  array<string, string>  $alternates
     */
    public function useAlternates(array $alternates): void
    {
        $this->alternates = $alternates;
    }

    /**
     * A language by its code. One that is not in the catalogue (an interface language with a code of its own)
     * still gets a name, from the interface languages.
     */
    public function language(string $code): Language
    {
        $name = (string) (config('app.available_locales')[$code] ?? $code);

        return $this->catalog->find($code) ?? new Language($code, $name, $name);
    }
}
