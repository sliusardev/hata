<?php

namespace Hata\Support\Admin\Lists;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;

/**
 * What one list reads from `RowActions`: the row actions resolved for its target.
 *
 * @extends Registry<RowAction>
 */
final class RowActionRegistry extends Registry
{
    public function name(): string
    {
        return 'row action';
    }

    public function describe(Entry $entry): string
    {
        return ($entry->isLink() ? 'link' : "handler [{$entry->handler}]").($entry->confirm !== null ? ', confirmed' : '');
    }

    /**
     * An action's permission needs the record, so the registry lets every action through and the list asks
     * `RowAction::allows()` per record.
     */
    public function allows(AdminUser $user, Entry $entry, ?object $context = null): bool
    {
        return true;
    }

    /**
     * Action keys travel in `wire:click` calls, so they hold no dots.
     */
    protected function keyPattern(): string
    {
        return '/^[a-z0-9]+([_-][a-z0-9]+)*\z/';
    }

    protected function keyProblem(): string
    {
        return 'its key is not lowercase letters and digits joined by hyphens or underscores';
    }

    protected function validate(Entry $entry): ?string
    {
        if ($entry->isLink() === ($entry->handler !== null)) {
            return 'it needs either a url or a handler, not both or neither';
        }

        if ($entry->handler !== null && ! class_exists($entry->handler)) {
            return "its handler [{$entry->handler}] is not a class";
        }

        if ($entry->handler !== null && ! method_exists($entry->handler, '__invoke')) {
            return "its handler [{$entry->handler}] has no __invoke method";
        }

        return null;
    }
}
