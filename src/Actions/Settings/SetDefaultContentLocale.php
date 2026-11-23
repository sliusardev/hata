<?php

namespace App\Actions\Settings;

use App\Exceptions\ContentLocaleException;
use App\Models\User;

class SetDefaultContentLocale extends ContentLocaleAction
{
    /**
     * Make an active content language the default one: it loses its address prefix and the old default gets
     * one, so every public address changes.
     *
     * With `$alsoInterface`, and when the admin is translated into that language, it also becomes the default
     * interface language (the admin and the sign-in pages for guests and users without a language).
     *
     * @throws ContentLocaleException
     */
    public function handle(User $actor, string $code, bool $alsoInterface = false): void
    {
        $this->authorize($actor);

        if (! $this->locales->has($code)) {
            throw ContentLocaleException::notAdded();
        }

        if (! $this->locales->isActive($code)) {
            throw ContentLocaleException::inactive();
        }

        $this->store(
            $this->locales->entries(),
            $code,
            $alsoInterface && $this->locales->language($code)->hasInterface() ? ['default_locale' => $code] : [],
        );
    }
}
