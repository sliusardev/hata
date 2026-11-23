<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The registry of the admin sidebar: groups, and the items in them.
 *
 * Hata's own sections and a module's register in a provider's `boot()`; nothing is resolved until the sidebar
 * renders, so a route that does not exist (yet) cannot break the boot. A mistake in a registration (an item in a group
 * that was never declared, a parent that does not exist or a parent cycle, a route that is not defined) throws a
 * LogicException naming the item in the local and testing environments and when `app.debug` is on, so a module's own
 * tests catch it; in production the item is skipped and a warning is logged. Other errors, such as a missing route
 * parameter, are never swallowed. Bound as a singleton.
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
     * The registration mistakes this process has logged already.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

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

        foreach ($items as $item) {
            if (! isset($this->groups[$item->group])) {
                $this->problem($item, "its group [{$item->group}] was never declared with group()");
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
            if ($item->parent === null) {
                $roots[] = $item;
            } elseif ($item->parent !== $item->key && isset($items[$item->parent])) {
                $children[$item->parent][] = $item;
            } else {
                $this->problem($item, "its parent [{$item->parent}] is not an item of the group [{$item->group}]");
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

        foreach ($items as $item) {
            if (! in_array($item, $ordered, true)) {
                $this->problem($item, 'it is part of a parent cycle');
            }
        }

        return $ordered;
    }

    private function canBuildUrl(AdminMenuItem $item): bool
    {
        try {
            $item->url();

            return true;
        } catch (RouteNotFoundException) {
            $this->problem($item, "its route [{$item->route}] is not defined");

            return false;
        }
    }

    /**
     * A registration mistake: thrown where a developer sees it (local and testing), skipped elsewhere and logged once
     * per process for each item, so a production site with `app.debug` on still renders its admin and a long-running
     * worker does not repeat the warning on every page.
     */
    private function problem(AdminMenuItem $item, string $problem): void
    {
        $message = "The admin menu item [{$item->key}] is left out: {$problem}.";

        if (app()->environment('local', 'testing')) {
            throw new LogicException($message);
        }

        if (isset(self::$warned[$message])) {
            return;
        }

        self::$warned[$message] = true;

        Log::warning($message);
    }
}
