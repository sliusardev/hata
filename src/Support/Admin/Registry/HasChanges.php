<?php

namespace Hata\Support\Admin\Registry;

/**
 * A copy of an immutable entry with some of its constructor arguments changed, for `Registry::modify()`:
 * `$entry->with(order: 5, label: 'Shop')`. Every public property must be a promoted constructor argument of the
 * same name.
 */
trait HasChanges
{
    public function with(mixed ...$changes): static
    {
        /** @phpstan-ignore new.static */
        return new static(...[...get_object_vars($this), ...$changes]);
    }
}
