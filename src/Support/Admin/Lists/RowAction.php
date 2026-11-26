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
 * being shown as a success toast (null: none); it refuses by throwing `ProtectedActionException` (or a
 * `ValidationException`), whose message is shown as a danger toast. `$confirm` is a translation key or text; with it
 * the action asks first in the list's shared dialog. `$visibleIn` is a `Closure(ListContext): bool`; without it the
 * action is not shown in the trash. `$permission` is checked per record: a string ability goes through
 * `Gate::forUser($user)->allows($ability, $record)`, a `Closure(AdminUser, Model, ListContext): bool` is called once
 * per row, so it must not query (use eager-loaded data).
 *
 * The tail options (`visibleIn`, `permission`, `order`, …) are best given as named arguments.
 */
final readonly class RowAction implements Entry
{
    use HasChanges;

    /**
     * @param  (Closure(Model): string)|null  $url
     * @param  class-string|null  $handler
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     * @param  string|Closure(AdminUser, Model, ListContext): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public ?Closure $url = null,
        public ?string $handler = null,
        public ?Closure $visibleIn = null,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public bool $newTab = false,
        public bool $danger = false,
        public ?string $confirm = null,
    ) {}

    /**
     * An item that links to an address worked out from the row's record.
     *
     * @param  Closure(Model): string  $url
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     * @param  string|Closure(AdminUser, Model, ListContext): bool|null  $permission
     */
    public static function link(string $key, string $label, string $icon, Closure $url, ?Closure $visibleIn = null, string|Closure|null $permission = null, int $order = 100, bool $newTab = false): self
    {
        return new self(key: $key, label: $label, icon: $icon, url: $url, visibleIn: $visibleIn, permission: $permission, order: $order, newTab: $newTab);
    }

    /**
     * An item that runs a handler class on the row's record.
     *
     * @param  class-string  $handler
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     * @param  string|Closure(AdminUser, Model, ListContext): bool|null  $permission
     */
    public static function handler(string $key, string $label, string $icon, string $handler, ?Closure $visibleIn = null, string|Closure|null $permission = null, int $order = 100, bool $danger = false, ?string $confirm = null): self
    {
        return new self(key: $key, label: $label, icon: $icon, handler: $handler, visibleIn: $visibleIn, permission: $permission, order: $order, danger: $danger, confirm: $confirm);
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }

    /**
     * Whether the user may take this action on this record, in the list the context names (passed to a permission
     * closure as its last argument).
     */
    public function allows(AdminUser $user, Model $record, ListContext $context): bool
    {
        if ($this->permission === null) {
            return true;
        }

        if ($this->permission instanceof Closure) {
            return (bool) ($this->permission)($user, $record, $context);
        }

        return Gate::forUser($user)->allows($this->permission, $record);
    }
}
