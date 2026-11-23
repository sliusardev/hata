<?php

namespace Hata\Http\Middleware;

use Closure;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function __construct(private readonly ContentLocales $locales) {}

    /**
     * Apply the interface language: the signed-in user's own, else the language of the site the visitor last
     * saw (when the interface is translated into it and it is still on), else the site's default.
     *
     * On public pages SetContentLocale runs after this and sets the language of the address.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(
            $this->interfaceLanguage($request->user()?->locale)
                ?? $this->lastSiteLanguage($request)
                ?? $this->locales->interfaceDefault(),
        );

        return $next($request);
    }

    private function interfaceLanguage(mixed $locale): ?string
    {
        return is_string($locale) && array_key_exists($locale, (array) config('hata.admin.interface_locales')) ? $locale : null;
    }

    private function lastSiteLanguage(Request $request): ?string
    {
        $locale = $request->hasSession() ? $request->session()->get(ContentLocales::SESSION_KEY) : null;
        $locale = $this->interfaceLanguage($locale);

        return $locale !== null && $this->locales->isActive($locale) ? $locale : null;
    }
}
