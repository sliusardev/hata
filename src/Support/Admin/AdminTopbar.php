<?php

namespace Hata\Support\Admin;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * The registry of the desktop top bar: links and components on its start and end sides. Bound as a singleton; the
 * mistakes (no target, an undefined route, an unknown side) are reported as in `Registry`.
 *
 * @extends Registry<TopbarItem>
 */
final class AdminTopbar extends Registry
{
    public function name(): string
    {
        return 'top bar item';
    }

    public function describe(Entry $entry): string
    {
        $what = match (true) {
            $entry->component !== null => "component [{$entry->component}]",
            $entry->route !== '' => "route [{$entry->route}]",
            default => "url [{$entry->url}]",
        };

        return "{$what} on the {$entry->side}";
    }

    /**
     * The items this user sees, by side, in order.
     *
     * @return array{start: list<TopbarItem>, end: list<TopbarItem>}
     */
    public function for(AdminUser $user): array
    {
        $items = $this->entriesFor($user);

        return [
            'start' => array_values(array_filter($items, fn (TopbarItem $item): bool => $item->side === 'start')),
            'end' => array_values(array_filter($items, fn (TopbarItem $item): bool => $item->side === 'end')),
        ];
    }

    protected function validate(Entry $entry): ?string
    {
        if (! in_array($entry->side, ['start', 'end'], true)) {
            return "its side [{$entry->side}] is not start or end";
        }

        if ($entry->component !== null) {
            return null;
        }

        if ($entry->route === '' && ($entry->url === null || $entry->url === '')) {
            return 'a link needs a route or a url';
        }

        try {
            $entry->href();
        } catch (RouteNotFoundException) {
            return "its route [{$entry->route}] is not defined";
        }

        return null;
    }
}
