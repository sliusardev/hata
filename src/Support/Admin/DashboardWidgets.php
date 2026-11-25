<?php

namespace Hata\Support\Admin;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Livewire\Livewire;

/**
 * The registry of the dashboard's widgets. Bound as a singleton; a widget whose component Livewire cannot resolve or
 * whose width is unknown is reported as in `Registry`.
 *
 * @extends Registry<DashboardWidget>
 */
final class DashboardWidgets extends Registry
{
    public function name(): string
    {
        return 'dashboard widget';
    }

    public function describe(Entry $entry): string
    {
        return "component [{$entry->component}], {$entry->width} width";
    }

    /**
     * The widgets this user sees, in order.
     *
     * @return list<DashboardWidget>
     */
    public function for(AdminUser $user): array
    {
        return $this->entriesFor($user);
    }

    protected function validate(Entry $entry): ?string
    {
        if (! in_array($entry->width, DashboardWidget::WIDTHS, true)) {
            return "its width [{$entry->width}] is not third, half or full";
        }

        return Livewire::exists($entry->component)
            ? null
            : "its component [{$entry->component}] cannot be resolved by Livewire";
    }
}
