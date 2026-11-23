<?php

namespace Hata\Support\Permissions;

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
     * The translation file prefix of each group's labels, e.g. `hata::permissions` or `shop::permissions`.
     *
     * @var array<string, string>
     */
    private array $prefixes = [];

    /**
     * Register actions for a resource group. Registering the same group again merges the actions.
     *
     * The labels are read from `{$translationPrefix}.groups.<group>` and `{$translationPrefix}.items.<permission>`
     * of the registering owner's translations; a site's or module's own groups use the default `permissions`
     * (the site's lang files), a package passes its namespace (`shop::permissions`).
     *
     * @param  list<string>  $actions
     */
    public function register(string $group, array $actions, string $translationPrefix = 'permissions'): static
    {
        foreach ([$group, ...$actions] as $segment) {
            if (preg_match('/^[a-z0-9_-]+$/', $segment) !== 1) {
                throw new InvalidArgumentException("Invalid permission segment [{$segment}].");
            }
        }

        $this->prefixes[$group] = $translationPrefix;
        $this->groups[$group] = array_values(array_unique([...($this->groups[$group] ?? []), ...$actions]));

        return $this;
    }

    /**
     * @param  array<string, list<string>>  $groups
     */
    public function registerMany(array $groups, string $translationPrefix = 'permissions'): static
    {
        foreach ($groups as $group => $actions) {
            $this->register($group, $actions, $translationPrefix);
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

    public function groupLabel(string $group): string
    {
        return __(($this->prefixes[$group] ?? 'permissions').".groups.{$group}");
    }

    public function permissionLabel(string $permission): string
    {
        $group = strstr($permission, '.', true);

        return __(($this->prefixes[$group] ?? 'permissions').".items.{$permission}");
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
