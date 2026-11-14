<?php

namespace App\Http\Middleware;

use App\Support\Localization\ContentLocales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides the content language of a public page from its address (routes registered with Route::localized()).
 *
 * No prefix is the default language; `/<code>/…` is that language when it is on, a 404 when it is off or not
 * added, and a redirect to the address without the prefix when it is the default one. Livewire runs this
 * middleware again on its update requests (it is a persistent middleware), against the page's original
 * address, so a component keeps the language of the page it lives on.
 */
class SetContentLocale
{
    public function __construct(private readonly ContentLocales $locales) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $prefix = $route?->parameter('locale');
        $code = $this->locales->codeForPrefix($prefix);
        // Livewire re-runs this middleware on its update requests, against the page's own address. Told by the
        // real request's route, not by a header any visitor could send.
        $fromLivewire = app(HandleRequests::class)->isLivewireRoute();

        if ($this->locales->isDefaultPrefix($prefix) && ! $fromLivewire) {
            // Not cacheable for good: after the default language changes, this prefix is a real address.
            return redirect($this->withoutPrefix($request, $prefix), 301)->header('Cache-Control', 'no-cache');
        }

        abort_unless($this->locales->isActive($code), 404);

        $route?->forgetParameter('locale');

        $name = $route?->getName();

        $this->locales->setCurrent($code, $route === null || $name === null ? null : [
            'name' => Str::after($name, ContentLocales::ROUTE_PREFIX),
            // Only what the address carries: a route's defaults (Route::view's view name) are parameters too.
            'parameters' => Arr::only($route->parameters(), $route->parameterNames()),
        ]);

        app()->setLocale($code);

        // A string the content language has no translation for shows in the site's default interface language.
        app('translator')->setFallback($this->locales->interfaceDefault());

        if (! $fromLivewire && $request->hasSession() && $request->session()->get(ContentLocales::SESSION_KEY) !== $code) {
            $request->session()->put(ContentLocales::SESSION_KEY, $code);
        }

        return $next($request);
    }

    private function withoutPrefix(Request $request, string $prefix): string
    {
        $path = '/'.ltrim(Str::after('/'.ltrim($request->path(), '/'), '/'.$prefix), '/');
        $query = $request->getQueryString();

        return url($path).($query === null ? '' : '?'.$query);
    }
}
