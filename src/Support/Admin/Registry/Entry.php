<?php

namespace Hata\Support\Admin\Registry;

use Closure;

/**
 * One entry of an admin registry (a menu item, a settings section, …): what the shared base needs to keep, order,
 * replace, modify and filter entries. Entries are immutable values; a change is a new entry (`HasChanges::with()`).
 */
interface Entry
{
    /**
     * Unique in its registry: lowercase letters and digits joined by hyphens or dots, unless the registry says otherwise.
     */
    public string $key { get; }

    /**
     * Lower comes first; entries of equal order keep the order they were added in.
     */
    public int $order { get; }

    /**
     * Who sees the entry: an ability checked through the Gate, a `Closure(AdminUser): bool`, or null for everyone.
     */
    public string|Closure|null $permission { get; }
}
