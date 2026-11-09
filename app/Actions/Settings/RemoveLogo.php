<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class RemoveLogo
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Delete the uploaded light or dark logo. The other one (or the default mark) takes its place.
     */
    public function handle(User $actor, string $variant): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown logo variant [{$variant}].");
        }

        $path = config("admin.logo.{$variant}");

        $this->settings->put(["logo_{$variant}" => null]);

        if (is_string($path) && $path !== '') {
            SiteLogo::disk()->delete($path);
        }
    }
}
