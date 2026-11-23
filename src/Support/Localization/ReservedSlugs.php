<?php

namespace App\Support\Localization;

use App\Http\Middleware\SetContentLocale;
use Illuminate\Support\Facades\Route;

/**
 * Names content may not use as the first segment of its address: they already mean something else.
 *
 * For the content types (pages, posts) to check when a slug is saved; nothing calls it on a public request.
 */
class ReservedSlugs
{
    public function __construct(private readonly LanguageCatalog $catalog) {}

    public function contains(string $slug): bool
    {
        return in_array(mb_strtolower(trim($slug, '/')), $this->all(), true);
    }

    /**
     * Every language code of the catalogue (a language may be switched on tomorrow), the first path segment
     * of every route that is not a localized public one, and the names the web server answers itself.
     *
     * @return list<string>
     */
    public function all(): array
    {
        $segments = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array(SetContentLocale::class, $route->middleware(), true)) {
                continue;
            }

            $segment = explode('/', trim($route->uri(), '/'))[0];

            if ($segment !== '' && ! str_starts_with($segment, '{')) {
                $segments[] = mb_strtolower($segment);
            }
        }

        return array_values(array_unique([
            ...$this->catalog->codes(),
            ...$segments,
            ...array_map(mb_strtolower(...), (array) config('content.reserved_slugs')),
        ]));
    }
}
