<?php

namespace Hata\Actions\Settings;

use Hata\Contracts\AdminUser;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Gate;

/**
 * What the actions that change the site's content languages share: the permission and the way the list is
 * stored. The list is always written whole, with the default language, so the first change also stores what
 * was derived until then.
 */
abstract class ContentLocaleAction
{
    public function __construct(
        protected readonly SiteSettings $settings,
        protected readonly ContentLocales $locales,
        protected readonly LanguageCatalog $catalog,
    ) {}

    protected function authorize(AdminUser $actor): void
    {
        Gate::forUser($actor)->authorize('settings.edit');
    }

    /**
     * @param  list<array{code: string, active: bool}>  $entries
     * @param  array<string, mixed>  $more  Other settings to store in the same write.
     */
    protected function store(array $entries, string $default, array $more = []): void
    {
        $this->settings->put([
            'content_locales' => $entries,
            'content_default_locale' => $default,
            ...$more,
        ]);
    }
}
