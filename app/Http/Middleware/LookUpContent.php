<?php

namespace App\Http\Middleware;

use App\Support\Content\ContentRouter;
use App\Support\Localization\ContentLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Looks up what a content address holds before the session starts (it is ordered before StartSession in
 * bootstrap/app.php), so a slug-shaped address with no record and no redirect (`/wp-admin`, `/xmlrpc`) answers 404
 * with no session, no cookie and at most two queries. Only on the `content` routes, which never serve Livewire.
 *
 * The language is read from the address as SetContentLocale reads it (ContentLocales::codeForPrefix()): a language
 * that is off or not added keeps its 404, and the default language's own prefix is left to SetContentLocale, which
 * redirects it. A hit, of any status, goes on with the lookup in the request's attributes; whether the viewer may
 * see a hidden record is decided after the session, by the controller.
 */
class LookUpContent
{
    public function __construct(
        private readonly ContentLocales $locales,
        private readonly ContentRouter $router,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $prefix = $route?->parameter('locale');

        if ($this->locales->isDefaultPrefix($prefix)) {
            return $next($request);
        }

        $code = $this->locales->codeForPrefix($prefix);
        $path = $route?->parameter('path');

        abort_unless($this->locales->isActive($code), 404);

        $lookup = is_string($path) ? $this->router->lookup($code, $path) : null;

        abort_if($lookup === null, 404);

        $request->attributes->set(ContentRouter::LOOKUP_ATTRIBUTE, $lookup);

        return $next($request);
    }
}
