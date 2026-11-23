<?php

namespace App\Support\Permissions;

use InvalidArgumentException;

/**
 * The permissions known to the application, grouped by resource.
 *
 * Filled from config/permissions.php and extendable by modules and themes through register().
 */
class PermissionRegistry
{
    /**
     * @var array<string, list<string>>
     */
    private array $groups = [];

    /**
     * Register actions for a resource group. Registering the same group again merges the actions.
     *
     * @param  list<string>  $actions
     */
    public function register(string $group, array $actions): static
    {
        foreach ([$group, ...$actions] as $segment) {
            if (preg_match('/^[a-z0-9_-]+$/', $segment) !== 1) {
                throw new InvalidArgumentException("Invalid permission segment [{$segment}].");
            }
        }

        $this->groups[$group] = array_values(array_unique([...($this->groups[$group] ?? []), ...$actions]));

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    public function registerMany(array $groups): static
    {
        foreach ($groups as $group => $actions) {
            $this->register($group, $actions);
        }

        return $this;
    }

    /**
     * Permission names grouped by resource, e.g. ['users' => ['users.view', 'users.edit']].
     *
     * @return array<string, list<string>>
     */
    public function groups(): array
    {
        $named = [];

        foreach ($this->groups as $group => $actions) {
            $named[$group] = array_map(fn (string $action): string => "{$group}.{$action}", $actions);
        }

        return $named;
    }

    /**
     * Every registered permission name.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return array_merge([], ...array_values($this->groups()));
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->all(), true);
    }
}
