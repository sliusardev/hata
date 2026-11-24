<?php

namespace Hata\Actions\Settings;

use Hata\Contracts\AdminUser;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Hata\Rules\MediaSelection;
use Hata\Support\Seo\PageMeta;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetShareImage
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Use a library image as the site's image for sharing and record where it is used.
     *
     * @throws ValidationException when the file is missing, not an image or not a shareable format (error key `media`)
     */
    public function handle(AdminUser $actor, int $mediaId): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        // Without media.view a file can't be browsed, so only the image already in place may be kept.
        if (config('hata.seo.image') !== $mediaId) {
            Gate::forUser($actor)->authorize('viewAny', Media::class);
        }

        Validator::make(
            ['media' => $mediaId],
            ['media' => [new MediaSelection(accept: 'image', multiple: false, extensions: PageMeta::IMAGE_EXTENSIONS)]],
            attributes: ['media' => __('hata::site_settings.seo.image')],
        )->validate();

        DB::transaction(function () use ($mediaId): void {
            $this->settings->put(['og_image' => $mediaId]);

            MediaUsage::sync(Setting::query()->where('key', 'og_image')->firstOrFail(), 'og_image', [$mediaId]);
        });
    }
}
