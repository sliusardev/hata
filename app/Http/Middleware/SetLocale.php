<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Apply the authenticated user's interface language, falling back to the application locale.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;

        app()->setLocale(
            $locale !== null && array_key_exists($locale, config('app.available_locales'))
                ? $locale
                : config('app.locale'),
        );

        return $next($request);
    }
}
