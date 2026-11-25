<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The registry of the admin sidebar: groups, and the items in them.
 *
 * Hata's own sections register while the application boots (before any provider's `boot()`), a module's in its
 * provider's `boot()`, and `remove()` and `modify()` work whatever the order (see `Registry`); nothing is resolved until the sidebar
 * renders, so a route that does not exist (yet) cannot break the boot. A mistake in a registration (an item in a group
 * that was never declared, a parent that does not exist or a parent cycle, a route that is not defined) throws a
 * LogicException naming the item in the local and testing environments and when `app.debug` is on, so a module's own
 * tests catch it; in production the item is skipped and a warning is logged. Other errors, such as a missing route
 * parameter, are never swallowed. Bound as a singleton.
 *
 * @extends Registry<AdminMenuItem>
 */
final class AdminMenu extends Registry
{
    /**
     * @var array<string, array{label: string, order: int, collapsible: bool}>
     */
    private array $groups = [];

    /**
     * @var list<array{string, Closure(array{label: string, order: int, collapsible: bool}): array{label: string, order: int, collapsible: bool}}>
     */
    private array $groupChanges = [];

    /**
     * @var list<Closure(): iterable<AdminMenuItem>>
     */
    private array $providers = [];

    public function name(): string
    {
        return 'admin menu item';
    }

    /**
     * Declares a group (or renames and reorders it). `$label` is a translation key or a plain string. A collapsible
     * group can be folded by the user in the sidebar.
     */
    public function group(string $key, string $label, int $order, bool $collapsible = false): static
    {
        $this->groups[$key] = ['label' => $label, 'order' => $order, 'collapsible' => $collapsible];

        return $this;
    }

    /**
     * Changes a group when the menu is built, whether it is declared before or after this call; a group that is not
     * declared is skipped.
     *
     * @param  Closure(array{label: string, order: int, collapsible: bool}): array{label: string, order: int, collapsible: bool}  $change
     */
    public function modifyGroup(string $key, Closure $change): static
    {
        $this->groupChanges[] = [$key, $change];

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
     * @return array<string, array{label: string, collapsible: bool, items: list<AdminMenuItem>}>
     */
    public function for(AdminUser $user): array
    {
        $groups = $this->resolvedGroups();
        $all = $this->all();

        foreach ($all as $item) {
            if (! isset($groups[$item->group])) {
                $this->problem($item->key, "its group [{$item->group}] was never declared with group()");
            }
        }

        $menu = [];

        foreach ($groups as $groupKey => $group) {
            $visible = array_values(array_filter(
                $this->inTreeOrder(array_values(array_filter($all, fn (AdminMenuItem $item): bool => $item->group === $groupKey))),
                fn (AdminMenuItem $item): bool => $this->allows($user, $item),
            ));

            if ($visible !== []) {
                $menu[$groupKey] = ['label' => $group['label'], 'collapsible' => $group['collapsible'], 'items' => $visible];
            }
        }

        return $menu;
    }

    protected function extraEntries(): iterable
    {
        foreach ($this->providers as $provider) {
            yield from $provider();
        }
    }

    protected function keyPattern(): string
    {
        return '/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/';
    }

    protected function keyProblem(): string
    {
        return 'its key is not segments of lowercase letters, digits, hyphens and underscores joined by dots';
    }

    protected function validate(Entry $entry): ?string
    {
        try {
            $entry->url();
        } catch (RouteNotFoundException) {
            return "its route [{$entry->route}] is not defined";
        }

        return null;
    }

    /**
     * The groups with their changes applied, in order (ties keep the order they were declared in).
     *
     * @return array<string, array{label: string, order: int, collapsible: bool}>
     */
    private function resolvedGroups(): array
    {
        $groups = $this->groups;

        foreach ($this->groupChanges as [$key, $change]) {
            if (isset($groups[$key])) {
                $groups[$key] = $change($groups[$key]);
            }
        }

        $position = array_flip(array_keys($groups));
        uksort($groups, fn (string $a, string $b): int => [$groups[$a]['order'], $position[$a]] <=> [$groups[$b]['order'], $position[$b]]);

        return $groups;
    }

    /**
     * The items of one group (already in order), each followed by its children. An item whose parent is not in the
     * group is a top-level one.
     *
     * @param  list<AdminMenuItem>  $list
     * @return list<AdminMenuItem>
     */
    private function inTreeOrder(array $list): array
    {
        $items = [];

        foreach ($list as $item) {
            $items[$item->key] = $item;
        }

        $children = [];
        $roots = [];

        foreach ($items as $item) {
            if ($item->parent === null) {
                $roots[] = $item;
            } elseif ($item->parent !== $item->key && isset($items[$item->parent])) {
                $children[$item->parent][] = $item;
            } else {
                $this->problem($item->key, "its parent [{$item->parent}] is not an item of the group [{$item->group}]");
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
                $this->problem($item->key, 'it is part of a parent cycle');
            }
        }

        return $ordered;
    }
}
