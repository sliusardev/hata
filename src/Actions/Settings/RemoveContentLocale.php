<?php

namespace Hata\Actions\Settings;

use App\Models\User;
use Hata\Exceptions\ContentLocaleException;
use Hata\Models\Entry;

class RemoveContentLocale extends ContentLocaleAction
{
    /**
     * Take a language off the site's list. Not the default one, nor one that has content records (those in the trash
     * count too): switching it off is how such a language is hidden.
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

        $records = Entry::withTrashed()->where('locale', $code)->count();

        if ($records > 0) {
            throw ContentLocaleException::hasContent($records);
        }

        $this->store(
            array_values(array_filter($this->locales->entries(), fn (array $entry): bool => $entry['code'] !== $code)),
            $this->locales->defaultCode(),
        );
    }
}
