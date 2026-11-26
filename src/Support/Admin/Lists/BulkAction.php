<?php

namespace Hata\Support\Admin\Lists;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;

/**
 * One button of a list's bulk bar, after Hata's own. `$handler` is the name of a class the container resolves and
 * calls once, as `__invoke(Collection $records, AdminUser $actor): ?string`, with the selected records of the
 * list's view; the returned text is shown as a success toast (null: none).
 *
 * `$label` is a translation key or a plain string. `$visibleIn` is a `Closure(ListContext): bool`; without it the
 * action is not shown in the trash. `$permission` is checked for the user when the actions are listed (there is no
 * single record): a string ability, or a `Closure(AdminUser, ListContext): bool`. `$confirm` (a translation key or
 * text) asks first in the list's shared dialog; a `$danger` action always asks. A handler refuses by throwing
 * `ProtectedActionException` (or a `ValidationException`): its message is shown as a danger toast.
 *
 * The tail options (`visibleIn`, `permission`, `order`, …) are best given as named arguments.
 */
final readonly class BulkAction implements Entry
{
    use HasChanges;

    /**
     * @param  class-string  $handler
     * @param  (Closure(ListContext): bool)|null  $visibleIn
     * @param  string|Closure(AdminUser, ListContext): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $handler,
        public ?Closure $visibleIn = null,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public bool $danger = false,
        public ?string $confirm = null,
    ) {}

    /**
     * Whether the action asks before it runs.
     */
    public function asksFirst(): bool
    {
        return $this->danger || $this->confirm !== null;
    }
}
