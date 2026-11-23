<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\Localization\ContentLocales;
use App\Support\Localization\LanguageCatalog;
use App\Support\Settings\SiteSettings;
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

    protected function authorize(User $actor): void
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
