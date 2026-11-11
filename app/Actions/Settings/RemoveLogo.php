<?php

namespace App\Actions\Settings;

use App\Models\MediaUsage;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class RemoveLogo
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Stop using the light or dark logo. The file stays in the library; the other logo (or the default mark) takes its place.
     */
    public function handle(User $actor, string $variant): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown logo variant [{$variant}].");
        }

        DB::transaction(function () use ($variant): void {
            $setting = Setting::query()->where('key', "logo_{$variant}")->first();

            if ($setting !== null) {
                MediaUsage::sync($setting, "logo_{$variant}", []);
            }

            $this->settings->put(["logo_{$variant}" => null]);
        });
    }
}
