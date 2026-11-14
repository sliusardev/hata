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
 * redirects it.
 *
 * A record that is not public (or a redirect to one) must answer exactly like a miss, or the response would tell
 * that a draft exists. So the decision follows the request, not the record (mayBeSignedIn()): a request without a
 * session cookie cannot come from a signed-in editor, and its miss and its hidden record are the same 404 here,
 * before the session; a request with the session cookie goes on in both cases, with the lookup (null for a miss) in
 * the request's attributes, and the controller answers both after the session, where a preview can be allowed.
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

        if (! self::mayBeSignedIn($request)) {
            // Only public content can be shown to this request: anything else is the same 404 as a miss.
            abort_if($lookup === null || ! $lookup->entry->isPublic(), 404);
        }

        // Set for a miss too (null), so the controller answers it without looking the address up again.
        $request->attributes->set(ContentRouter::LOOKUP_ATTRIBUTE, $lookup);

        return $next($request);
    }

    /**
     * Whether the request could come from a signed-in user: it carries the session cookie
     * (`config('session.cookie')`). Its presence is enough; it is not decrypted or read. Every signed-in visitor has
     * it, and probing bots send no cookies.
     */
    public static function mayBeSignedIn(Request $request): bool
    {
        return $request->cookies->has((string) config('session.cookie'));
    }
}
