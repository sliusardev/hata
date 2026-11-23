<?php

namespace Hata\Actions\Settings;

use App\Models\User;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Hata\Rules\MediaSelection;
use Hata\Support\Settings\SiteLogo;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SetLogo
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Use a library image as the light or dark logo and record where it is used.
     *
     * @throws ValidationException when the file is missing, not an image or not a logo format (error key `media`)
     */
    public function handle(User $actor, string $variant, int $mediaId): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown logo variant [{$variant}].");
        }

        // Without media.view a file can't be browsed, so only the logo already in place may be kept.
        if (config("admin.logo.{$variant}") !== $mediaId) {
            Gate::forUser($actor)->authorize('viewAny', Media::class);
        }

        Validator::make(
            ['media' => $mediaId],
            ['media' => [new MediaSelection(accept: 'image', multiple: false, extensions: SiteLogo::EXTENSIONS)]],
            attributes: ['media' => __("site_settings.logo.{$variant}")],
        )->validate();

        $this->assign($variant, $mediaId);
    }

    /**
     * Store the media id as the logo and record the usage, without any checks.
     * Callers must have validated the id (`handle()`) or know the file (the import migration).
     */
    public function assign(string $variant, int $mediaId): void
    {
        DB::transaction(function () use ($variant, $mediaId): void {
            $this->settings->put(["logo_{$variant}" => $mediaId]);

            MediaUsage::sync(Setting::query()->where('key', "logo_{$variant}")->firstOrFail(), "logo_{$variant}", [$mediaId]);
        });
    }
}
