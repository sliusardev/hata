<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;

/**
 * One section of the site settings page.
 *
 * `$label` is a translation key or a plain string (rendered with `__()`). `$component` is a Livewire component name
 * or class that renders the section. `$permission` is a gate ability, a `Closure(AdminUser): bool`, or null for any
 * admin.
 */
final readonly class SettingsSection
{
    /**
     * @param  string|Closure(AdminUser): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $icon,
        public string $component,
        public string|Closure|null $permission = 'settings.edit',
        public int $order = 100,
    ) {}
}
