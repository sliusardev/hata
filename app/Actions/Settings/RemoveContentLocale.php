<?php

namespace App\Actions\Settings;

use App\Exceptions\ContentLocaleException;
use App\Models\User;

class RemoveContentLocale extends ContentLocaleAction
{
    /**
     * Take a language off the site's list. Not the default one.
     *
     * @throws ContentLocaleException
     */
    public function handle(User $actor, string $code): void
    {
        $this->authorize($actor);

        if (! $this->locales->has($code)) {
            throw ContentLocaleException::notAdded();
        }

        if ($code === $this->locales->defaultCode()) {
            throw ContentLocaleException::isDefault();
        }

        $this->store(
            array_values(array_filter($this->locales->entries(), fn (array $entry): bool => $entry['code'] !== $code)),
            $this->locales->defaultCode(),
        );
    }
}
