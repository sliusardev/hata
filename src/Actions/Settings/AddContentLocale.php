<?php

namespace Hata\Actions\Settings;

use Hata\Contracts\AdminUser;
use Hata\Exceptions\ContentLocaleException;

class AddContentLocale extends ContentLocaleAction
{
    /**
     * Add a language of the catalogue to the site. It starts switched off, so nothing is public until the
     * administrator turns it on.
     *
     * @throws ContentLocaleException
     */
    public function handle(AdminUser $actor, string $code): void
    {
        $this->authorize($actor);

        if (! $this->catalog->has($code)) {
            throw ContentLocaleException::unknown();
        }

        if ($this->locales->has($code)) {
            throw ContentLocaleException::alreadyAdded();
        }

        $this->store([...$this->locales->entries(), ['code' => $code, 'active' => false]], $this->locales->defaultCode());
    }
}
