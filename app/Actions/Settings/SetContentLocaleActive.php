<?php

namespace App\Actions\Settings;

use App\Exceptions\ContentLocaleException;
use App\Models\User;

class SetContentLocaleActive extends ContentLocaleAction
{
    /**
     * Switch a content language on or off. While it is off, its addresses answer 404. The default language is
     * always on.
     *
     * @throws ContentLocaleException
     */
    public function handle(User $actor, string $code, bool $active): void
    {
        $this->authorize($actor);

        if (! $this->locales->has($code)) {
            throw ContentLocaleException::notAdded();
        }

        if ($code === $this->locales->defaultCode()) {
            throw ContentLocaleException::isDefault();
        }

        $entries = array_map(
            fn (array $entry): array => $entry['code'] === $code ? ['code' => $code, 'active' => $active] : $entry,
            $this->locales->entries(),
        );

        $this->store($entries, $this->locales->defaultCode());
    }
}
