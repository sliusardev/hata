<?php

namespace Hata\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRegistrationIsEnabled
{
    /**
     * Hide Fortify's registration routes while registration is turned off in the site settings.
     *
     * The routes are always registered, because the setting lives in the database and Fortify
     * decides which routes exist before it can be read.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && ! config('admin.registration_enabled')) {
            abort(404);
        }

        return $next($request);
    }
}
