<?php

namespace Hata\Support\Admin\Lists;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;

/**
 * One column of an admin list, after the fixed first column and before the "⋮" column.
 *
 * `$label` is a translation key or a plain string. `$value` is a `Closure(Model, ListContext): string|Htmlable|null`
 * (a string is escaped) or the name of a Blade view rendered with `['record' => …, 'context' => …]`. `$with` is an
 * array for `->with()` or a `Closure(Builder): void` (`withCount`, `addSelect`), applied once per page, never per
 * row. `$sort` makes the column sortable by its key: `Closure(Builder, string $direction): void`. `$visibleIn` is a
 * `Closure(ListContext): bool` (null: every tab). `$hideBelow` is `md` or `lg`, `$align` is `start`, `center` or `end`.
 */
final readonly class TableColumn implements Entry
{
    use HasChanges;

    public const array HIDE_BELOW = ['md', 'lg'];

    public const array ALIGNS = ['start', 'center', 'end'];

    /**
     * @param  array<int|string, mixed>|Closure  $with
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label,
        public Closure|string $value,
        public array|Closure $with = [],
        public ?Closure $sort = null,
        public ?Closure $visibleIn = null,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public ?string $hideBelow = null,
        public string $align = 'start',
    ) {}
}
