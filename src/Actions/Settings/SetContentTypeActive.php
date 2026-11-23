<?php

namespace Hata\Actions\Settings;

use Hata\Contracts\AdminUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Gate;

class SetContentTypeActive
{
    public function __construct(private readonly SiteSettings $settings, private readonly ContentTypes $types) {}

    /**
     * Switches a content type on or off for the site. While it is off it has no pages in the admin and its
     * addresses answer 404; its records, their translations and the permissions stay as they are, so switching it
     * on brings everything back. Needs `settings.edit`.
     *
     * @throws ProtectedActionException for a type that is not registered or that the site cannot do without
     */
    public function handle(AdminUser $actor, string $key, bool $active): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        $type = $this->types->find($key) ?? throw ProtectedActionException::because('hata::site_settings.content.messages.unknown');

        if ($type->required() && ! $active) {
            throw ProtectedActionException::because('hata::site_settings.content.messages.required', ['type' => $type->pluralLabel()]);
        }

        // Only keys of registered types are kept, so a type removed from the code leaves nothing behind.
        $disabled = array_values(array_filter(
            $this->types->disabledKeys(),
            fn (string $disabledKey): bool => $disabledKey !== $key && $this->types->find($disabledKey) !== null,
        ));

        if (! $active) {
            $disabled[] = $key;
        }

        $this->settings->put(['content_disabled_types' => $disabled]);
    }
}
