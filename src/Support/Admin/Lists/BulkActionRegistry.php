<?php

namespace Hata\Support\Admin\Lists;

use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;

/**
 * What one list reads from `BulkActions`: the bulk actions resolved for its target.
 *
 * @extends Registry<BulkAction>
 */
final class BulkActionRegistry extends Registry
{
    public function name(): string
    {
        return 'bulk action';
    }

    public function describe(Entry $entry): string
    {
        return "handler [{$entry->handler}]".($entry->asksFirst() ? ', confirmed' : '');
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
        if (! class_exists($entry->handler)) {
            return "its handler [{$entry->handler}] is not a class";
        }

        return method_exists($entry->handler, '__invoke') ? null : "its handler [{$entry->handler}] has no __invoke method";
    }
}
