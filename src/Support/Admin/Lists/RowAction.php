<?php

namespace Hata\Support\Admin\Lists;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * One item of a list's "⋮" menu, after Hata's own, behind a separator. Made with `link()` or `handler()`.
 *
 * `$label` is a translation key or a plain string. A link's `$url` is a `Closure(Model): string`; a handler is the
 * name of a class the container resolves and calls as `__invoke(Model, AdminUser): ?string`, the returned text
 * being shown as a success toast (null: none). `$confirm` is a translation key or text; with it the action asks
 * first in the list's shared dialog. `$permission` is checked per record: a string ability goes through
 * `Gate::forUser($user)->allows($ability, $record)`, a `Closure(AdminUser, Model): bool` is called once per row, so
 * it must not query (use eager-loaded data). `$visibleIn` is a `Closure(ListContext): bool`; without it the action is
 * not shown in the trash.
 */
final readonly class RowAction implements Entry
{
    use HasChanges;

    /**
     * @param  (Closure(Model): string)|null  $url
     * @param  class-string|null  $handler
     * @param  string|Closure(AdminUser, Model): bool|null  $permission
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public ?Closure $url = null,
        public ?string $handler = null,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public ?Closure $visibleIn = null,
        public bool $newTab = false,
        public bool $danger = false,
        public ?string $confirm = null,
    ) {}

    /**
     * An item that links to an address worked out from the row's record.
     *
     * @param  Closure(Model): string  $url
     * @param  string|Closure(AdminUser, Model): bool|null  $permission
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     */
    public static function link(string $key, string $label, string $icon, Closure $url, string|Closure|null $permission = null, int $order = 100, ?Closure $visibleIn = null, bool $newTab = false): self
    {
        return new self(key: $key, label: $label, icon: $icon, url: $url, permission: $permission, order: $order, visibleIn: $visibleIn, newTab: $newTab);
    }

    /**
     * An item that runs a handler class on the row's record.
     *
     * @param  class-string  $handler
     * @param  string|Closure(AdminUser, Model): bool|null  $permission
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     */
    public static function handler(string $key, string $label, string $icon, string $handler, string|Closure|null $permission = null, int $order = 100, ?Closure $visibleIn = null, bool $danger = false, ?string $confirm = null): self
    {
        return new self(key: $key, label: $label, icon: $icon, handler: $handler, permission: $permission, order: $order, visibleIn: $visibleIn, danger: $danger, confirm: $confirm);
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }

    /**
     * Whether the user may take this action on this record.
     */
    public function allows(AdminUser $user, Model $record): bool
    {
        if ($this->permission === null) {
            return true;
        }

        if ($this->permission instanceof Closure) {
            return (bool) ($this->permission)($user, $record);
        }

        return Gate::forUser($user)->allows($this->permission, $record);
    }
}
