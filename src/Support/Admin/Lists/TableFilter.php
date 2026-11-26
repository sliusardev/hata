<?php

namespace Hata\Support\Admin\Lists;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;

/**
 * One filter of an admin list, drawn as a select after the list's own filters. `$label` is a translation key or a
 * plain string. `$options` is a `Closure(ListContext): array<string, string>` (value => label), called once per
 * render. `$apply` is a `Closure(Builder, string): void`, called only for a value that is one of the options.
 * `$visibleIn` is a `Closure(ListContext): bool` (null: every tab). `$permission` is a string ability or a
 * `Closure(AdminUser, ListContext): bool`.
 *
 * The options after `apply` are best given as named arguments.
 */
final readonly class TableFilter implements Entry
{
    use HasChanges;

    /**
     * @param  Closure(ListContext): array<array-key, string>  $options
     * @param  Closure(\Illuminate\Database\Eloquent\Builder<*>, string): mixed  $apply
     * @param  string|Closure(AdminUser, ListContext): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label,
        public Closure $options,
        public Closure $apply,
        public ?Closure $visibleIn = null,
        public string|Closure|null $permission = null,
        public int $order = 100,
    ) {}
}
