<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;

/**
 * One widget of the admin dashboard: a Livewire component (a subclass of `Hata\Livewire\Admin\Widget`) in a grid cell.
 *
 * `$width` is `third`, `half` or `full` of the row on a wide screen; below it every widget is full width.
 * `$permission` works as for the menu items.
 */
final readonly class DashboardWidget implements Entry
{
    use HasChanges;

    public const array WIDTHS = ['third', 'half', 'full'];

    /**
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $component,
        public string|Closure|null $permission = null,
        public string $width = 'third',
        public int $order = 100,
    ) {}

    /**
     * The grid classes of the widget's cell, written out in full so Tailwind finds them.
     */
    public function cellClass(): string
    {
        return match ($this->width) {
            'half' => 'md:col-span-3',
            'full' => 'md:col-span-6',
            default => 'md:col-span-2',
        };
    }
}
