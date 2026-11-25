<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;
use WeakMap;

/**
 * One link of the admin sidebar.
 *
 * `$label` is a translation key or a plain string (rendered with `__()`, which returns a missing key unchanged).
 * `$permission` is a gate ability checked with `can`, a `Closure(AdminUser): bool`, or null for everyone signed in.
 * `$activePattern` is a `routeIs()` pattern (several may be joined with `|`); by default the item's own route.
 * `$parent` is the key of the item it is nested under: it follows its parent in the list, whatever its own order.
 * `$routeParameters` are passed to `route()`, and the item counts as the current one only while the current route
 * carries the same values for them (one `admin.content.index` route serves every content type).
 */
final readonly class AdminMenuItem implements Entry
{
    use HasChanges;

    /**
     * @param  string|Closure(AdminUser): bool|null  $permission
     * @param  array<string, string>  $routeParameters
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $route,
        public string|Closure|null $permission,
        public string $group,
        public int $order = 100,
        public ?string $activePattern = null,
        public ?string $parent = null,
        public array $routeParameters = [],
    ) {}

    public function isVisibleTo(AdminUser $user): bool
    {
        return app(AdminMenu::class)->allows($user, $this);
    }

    /**
     * Built once per item object: the menu checks it and the sidebar prints it. Relative, so the memo holds nothing
     * of the request: a worker that serves many requests (Octane) never prints the first request's host.
     */
    public function url(): string
    {
        static $urls = new WeakMap;

        return $urls[$this] ??= route($this->route, $this->routeParameters, absolute: false);
    }

    public function isCurrent(): bool
    {
        $request = request();

        if (! $request->routeIs(...explode('|', $this->activePattern ?? $this->route))) {
            return false;
        }

        foreach ($this->routeParameters as $name => $value) {
            if ((string) $request->route($name) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
