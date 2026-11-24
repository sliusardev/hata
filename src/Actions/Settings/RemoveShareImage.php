<?php

namespace Hata\Actions\Settings;

use Hata\Contracts\AdminUser;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RemoveShareImage
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Stop using the image for sharing. The file stays in the library.
     */
    public function handle(AdminUser $actor): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        DB::transaction(function (): void {
            $setting = Setting::query()->where('key', 'og_image')->first();

            if ($setting !== null) {
                MediaUsage::sync($setting, 'og_image', []);
            }

            $this->settings->put(['og_image' => null]);
        });
    }
}
