<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The registry of the admin sidebar: groups, and the items in them.
 *
 * Hata's own sections and a module's register in a provider's `boot()`; nothing is resolved until the sidebar
 * renders, so a route that does not exist (yet) cannot break the boot. An item whose route cannot be built is left
 * out, and logged as a warning in the local environment. Bound as a singleton.
 */
final class AdminMenu
{
    /**
     * @var array<string, array{label: string, order: int}>
     */
    private array $groups = [];

    /**
     * @var array<string, AdminMenuItem>
     */
    private array $items = [];

    /**
     * @var list<Closure(): iterable<AdminMenuItem>>
     */
    private array $providers = [];

    /**
     * Declares a group (or renames and reorders it). `$label` is a translation key or a plain string.
     */
    public function group(string $key, string $label, int $order): static
    {
        $this->groups[$key] = ['label' => $label, 'order' => $order];

        return $this;
    }

    /**
     * Adds an item; one with the same key replaces the earlier one.
     */
    public function add(AdminMenuItem $item): static
    {
        $this->items[$item->key] = $item;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->items[$key]);

        return $this;
    }

    /**
     * Registers items that are worked out each time the menu is built, for items that follow data which can change
     * after boot (the content types and their on/off switch). They are added after the fixed items.
     *
     * @param  Closure(): iterable<AdminMenuItem>  $provider
     */
    public function lazy(Closure $provider): static
    {
        $this->providers[] = $provider;

        return $this;
    }

    /**
     * The menu this user sees: groups in order, each with its visible items (a parent's children right after it),
     * and no empty group.
     *
     * @return array<string, array{label: string, items: list<AdminMenuItem>}>
     */
    public function for(AdminUser $user): array
    {
        $items = $this->items;

        foreach ($this->providers as $provider) {
            foreach ($provider() as $item) {
                $items[$item->key] = $item;
            }
        }

        $menu = [];

        foreach ($this->sortedGroups() as $groupKey => $group) {
            $visible = array_values(array_filter(
                $this->inTreeOrder(array_filter($items, fn (AdminMenuItem $item): bool => $item->group === $groupKey)),
                fn (AdminMenuItem $item): bool => $item->isVisibleTo($user) && $this->canBuildUrl($item),
            ));

            if ($visible !== []) {
                $menu[$groupKey] = ['label' => $group['label'], 'items' => $visible];
            }
        }

        return $menu;
    }

    /**
     * @return array<string, array{label: string, order: int}>
     */
    private function sortedGroups(): array
    {
        $position = array_flip(array_keys($this->groups));
        $keys = array_keys($this->groups);
        usort($keys, fn (string $a, string $b): int => [$this->groups[$a]['order'], $position[$a]] <=> [$this->groups[$b]['order'], $position[$b]]);

        return array_combine($keys, array_map(fn (string $key): array => $this->groups[$key], $keys));
    }

    /**
     * The items of one group by order (ties keep the order they were added in), each followed by its children. An
     * item whose parent is not in the group is a top-level one.
     *
     * @param  array<string, AdminMenuItem>  $items
     * @return list<AdminMenuItem>
     */
    private function inTreeOrder(array $items): array
    {
        $position = array_flip(array_keys($items));
        uasort($items, fn (AdminMenuItem $a, AdminMenuItem $b): int => [$a->order, $position[$a->key]] <=> [$b->order, $position[$b->key]]);

        $children = [];
        $roots = [];

        foreach ($items as $item) {
            if ($item->parent !== null && $item->parent !== $item->key && isset($items[$item->parent])) {
                $children[$item->parent][] = $item;
            } else {
                $roots[] = $item;
            }
        }

        $ordered = [];
        $place = function (AdminMenuItem $item, array $path) use (&$place, &$ordered, $children): void {
            $ordered[] = $item;

            foreach ($children[$item->key] ?? [] as $child) {
                if (! in_array($child->key, $path, true)) {
                    $place($child, [...$path, $item->key]);
                }
            }
        };

        foreach ($roots as $root) {
            $place($root, []);
        }

        return $ordered;
    }

    private function canBuildUrl(AdminMenuItem $item): bool
    {
        try {
            $item->url();

            return true;
        } catch (Throwable $e) {
            if (app()->isLocal()) {
                Log::warning("The admin menu item [{$item->key}] was left out: its route [{$item->route}] cannot be built.", ['exception' => $e->getMessage()]);
            }

            return false;
        }
    }
}
