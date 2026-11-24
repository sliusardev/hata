<?php

namespace Hata\Models;

use Hata\Contracts\AdminUser;
use Hata\Contracts\UsesMedia;
use Hata\Support\Settings\SiteLogo;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * One site setting stored by the admin. Read and written through Hata\Support\Settings\SiteSettings.
 *
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model implements UsesMedia
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function mediaUsageLabel(string $field): string
    {
        return $field === 'logo_dark' ? __('hata::site_settings.logo.usage_dark') : __('hata::site_settings.logo.usage_light');
    }

    public function mediaUsageUrl(string $field, ?AdminUser $viewer): ?string
    {
        return $viewer?->can('settings.edit') ? route('admin.settings.edit', ['tab' => 'appearance']) : null;
    }

    /**
     * Stop using a logo whose file is being deleted. Runs inside the delete's transaction; the settings cache
     * and the config are refreshed only after it commits (see SiteSettings::put()).
     */
    public function forgetMedia(string $field, int $mediaId): void
    {
        $variants = array_map(fn (string $variant): string => "logo_{$variant}", SiteLogo::VARIANTS);

        if (in_array($field, $variants, true) && $this->key === $field && (int) $this->value === $mediaId) {
            app(SiteSettings::class)->put([$field => null]);
        }
    }
}
