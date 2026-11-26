<?php

namespace Hata\Support\Admin\Forms;

use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;

/**
 * What one form reads from `FormCards`: the cards resolved for its target.
 *
 * @extends Registry<FormCard>
 */
final class FormCardRegistry extends Registry
{
    public function name(): string
    {
        return 'form card';
    }

    public function describe(Entry $entry): string
    {
        return "extension [{$entry->extension}] in [{$entry->area}]";
    }

    /**
     * Card keys are segments of the `extensions.<key>.<field>` model path, so they hold no dots.
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
        if (! class_exists($entry->extension) || ! is_subclass_of($entry->extension, FormExtension::class)) {
            return "its extension [{$entry->extension}] is not a class extending ".FormExtension::class;
        }

        if (! in_array($entry->area, ['main', 'side'], true)) {
            return "its area [{$entry->area}] is not main or side";
        }

        return null;
    }
}
