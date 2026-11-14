<?php

namespace App\Livewire\Forms;

use App\Enums\SystemRole;
use App\Support\ColorScheme\ColorScheme;
use App\Support\Localization\ContentLocales;
use App\Support\Media\ServerCapabilities;
use App\Support\Settings\SiteSettings;
use Closure;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Form;

class SiteSettingsForm extends Form
{
    public string $site_name = '';

    public string $default_locale = '';

    public string $timezone = '';

    public bool $registration_enabled = false;

    /**
     * Role name, or an empty string for "no role".
     */
    public string $default_role = '';

    /**
     * "server" keeps the mailer from .env, "smtp" uses the SMTP fields below.
     */
    public string $mail_mailer = 'server';

    public string $mail_host = '';

    public string $mail_port = '';

    public string $mail_scheme = 'smtp';

    public string $mail_username = '';

    /**
     * Never filled from storage. Left empty, it keeps the stored password.
     */
    public string $mail_password = '';

    public string $mail_from_address = '';

    public string $mail_from_name = '';

    /**
     * A ColorScheme::PRESETS key or "custom".
     */
    public string $color_scheme = 'neutral';

    /**
     * The company color of the custom scheme (#rrggbb). Kept when a preset is chosen.
     */
    public string $color_accent = '#2563eb';

    public string $color_gray = 'neutral';

    public bool $color_tint_sidebar = true;

    public bool $media_optimize = true;

    /**
     * One of MEDIA_MAX_SIDES: a size in pixels or "none".
     */
    public string $media_max_side = '2560';

    public string $media_quality = '80';

    /**
     * "webp" or "original". AVIF is shown in the tab but not selectable yet.
     */
    public string $media_format = 'webp';

    public bool $media_strip_exif = true;

    public bool $media_client_resize = true;

    public bool $media_keep_originals = true;

    public string $media_max_image_mb = '30';

    public string $media_max_document_mb = '20';

    /**
     * Where new files go: "public" (this server) or "s3".
     */
    public string $media_storage = 'public';

    public string $media_s3_endpoint = '';

    public string $media_s3_region = '';

    public string $media_s3_bucket = '';

    public string $media_s3_key = '';

    /**
     * Never filled from storage. Left empty, it keeps the stored secret.
     */
    public string $media_s3_secret = '';

    public bool $media_s3_path_style = false;

    public string $media_s3_public_url = '';

    public string $media_s3_prefix = '';

    /**
     * @var list<string>
     */
    public const array MEDIA_MAX_SIDES = ['1920', '2560', '3840', 'none'];

    /**
     * Fill the form with the values in effect (stored settings over .env).
     */
    public function fillFromSettings(SiteSettings $settings): void
    {
        $this->site_name = (string) config('app.name');
        $this->default_locale = app(ContentLocales::class)->interfaceDefault();
        $this->timezone = (string) config('admin.timezone');
        $this->registration_enabled = (bool) config('admin.registration_enabled');
        $this->default_role = (string) config('admin.default_role');
        $this->mail_mailer = $settings->has('mail_mailer') ? 'smtp' : 'server';
        $this->mail_from_address = (string) config('mail.from.address');
        $this->mail_from_name = (string) config('mail.from.name');
        $this->color_scheme = (string) config('admin.colors.scheme');
        $this->color_accent = ColorScheme::isHexColor(config('admin.colors.accent')) ? (string) config('admin.colors.accent') : '#2563eb';
        $this->color_gray = (string) config('admin.colors.gray');
        $this->color_tint_sidebar = (bool) config('admin.colors.tint_sidebar');
        $this->media_optimize = (bool) config('media.optimize.enabled');
        $this->media_max_side = is_int(config('media.optimize.max_side')) ? (string) config('media.optimize.max_side') : 'none';
        $this->media_quality = (string) config('media.optimize.quality');
        $this->media_format = (string) config('media.optimize.format');
        $this->media_strip_exif = (bool) config('media.optimize.strip_exif');
        $this->media_client_resize = (bool) config('media.optimize.client_resize');
        $this->media_keep_originals = (bool) config('media.optimize.keep_originals');
        $this->media_max_image_mb = (string) config('media.limits.image_mb');
        $this->media_max_document_mb = (string) config('media.limits.document_mb');
        $this->media_storage = config('media.disk') === 's3' ? 's3' : 'public';
        $this->media_s3_endpoint = (string) config('filesystems.disks.s3.endpoint');
        $this->media_s3_region = (string) config('filesystems.disks.s3.region');
        $this->media_s3_bucket = (string) config('filesystems.disks.s3.bucket');
        $this->media_s3_key = (string) config('filesystems.disks.s3.key');
        $this->media_s3_path_style = (bool) config('filesystems.disks.s3.use_path_style_endpoint');
        $this->media_s3_public_url = (string) config('filesystems.disks.s3.url');
        $this->media_s3_prefix = (string) config('filesystems.disks.s3.root');

        if ($this->mail_mailer === 'smtp') {
            $this->mail_host = (string) config('mail.mailers.smtp.host');
            $this->mail_port = (string) config('mail.mailers.smtp.port');
            $this->mail_scheme = config('mail.mailers.smtp.scheme') === 'smtps' ? 'smtps' : 'smtp';
            $this->mail_username = (string) config('mail.mailers.smtp.username');
        }
    }

    /**
     * Storage fields stored only when the admin changes them, so untouched ones keep following .env.
     *
     * @var list<string>
     */
    public const array STORAGE_FIELDS = ['media_storage', 'media_s3_endpoint', 'media_s3_region', 'media_s3_bucket', 'media_s3_key', 'media_s3_secret', 'media_s3_path_style', 'media_s3_public_url', 'media_s3_prefix'];

    /**
     * Fields of each settings tab, saved independently of each other.
     *
     * @var array<string, list<string>>
     */
    public const array SECTIONS = [
        'general' => ['site_name', 'timezone'],
        // The list of content languages changes at once through its own actions; Save stores the interface default.
        'languages' => ['default_locale'],
        'access' => ['registration_enabled', 'default_role'],
        'mail' => ['mail_mailer', 'mail_host', 'mail_port', 'mail_scheme', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'],
        // Logos are uploaded and removed right away, without the Save button.
        'logo' => [],
        'colors' => ['color_scheme', 'color_accent', 'color_gray', 'color_tint_sidebar'],
        'media' => ['media_optimize', 'media_max_side', 'media_quality', 'media_format', 'media_strip_exif', 'media_client_resize', 'media_keep_originals', 'media_max_image_mb', 'media_max_document_mb', 'media_storage', 'media_s3_endpoint', 'media_s3_region', 'media_s3_bucket', 'media_s3_key', 'media_s3_secret', 'media_s3_path_style', 'media_s3_public_url', 'media_s3_prefix'],
    ];

    /**
     * Whether a field of the tab differs from the saved values. A typed SMTP password always
     * counts as a change, because the stored one is never loaded into the form.
     *
     * @param  array<string, mixed>  $savedValues  Values of the form as they were loaded.
     */
    public function hasChangesIn(string $section, array $savedValues): bool
    {
        foreach (self::SECTIONS[$section] as $field) {
            if (in_array($field, ['mail_password', 'media_s3_secret'], true)) {
                if (filled($this->{$field})) {
                    return true;
                }

                continue;
            }

            if ((string) $this->{$field} !== (string) ($savedValues[$field] ?? '')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate the fields of one tab. Empty optional fields become null.
     *
     * @return array<string, string|int|bool|null>
     */
    public function validateSection(string $section): array
    {
        $rules = array_intersect_key($this->rules(), array_flip(self::SECTIONS[$section]));

        /** @var array<string, string|int|bool|null> $validated */
        $validated = $this->validate($rules);

        $data = [];

        foreach (self::SECTIONS[$section] as $field) {
            $value = $validated[$field] ?? null;

            $data[$field] = match (true) {
                in_array($field, ['registration_enabled', 'color_tint_sidebar', 'media_optimize', 'media_strip_exif', 'media_client_resize', 'media_keep_originals', 'media_s3_path_style'], true) => (bool) $value,
                $field === 'media_max_side' => $value === 'none' ? 0 : (int) $value,
                in_array($field, ['media_quality', 'media_max_image_mb', 'media_max_document_mb'], true) => (int) $value,
                $field === 'color_accent' => filled($value) ? strtolower((string) $value) : null,
                in_array($field, ['media_s3_public_url', 'media_s3_prefix'], true) => filled($value) ? trim((string) $value, '/') : null,
                $field === 'mail_port' => filled($value) ? (int) $value : null,
                default => filled($value) ? $value : null,
            };
        }

        return $data;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function rules(): array
    {
        return [
            'site_name' => ['required', 'string', 'max:255'],
            'default_locale' => ['required', Rule::in(array_keys(config('app.available_locales')))],
            'timezone' => ['required', 'timezone:all'],
            'registration_enabled' => ['boolean'],
            'default_role' => ['nullable', 'string', Rule::exists('roles', 'name'), Rule::notIn([SystemRole::SuperAdmin->value])],
            'mail_mailer' => ['required', Rule::in(['server', 'smtp'])],
            'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
            'mail_port' => ['nullable', 'required_if:mail_mailer,smtp', 'integer', 'between:1,65535'],
            'mail_scheme' => ['nullable', 'required_if:mail_mailer,smtp', Rule::in(['smtp', 'smtps'])],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['required', 'email', 'max:255'],
            'mail_from_name' => ['required', 'string', 'max:255'],
            'color_scheme' => ['required', Rule::in([...array_keys(ColorScheme::PRESETS), ColorScheme::CUSTOM])],
            'color_accent' => ['nullable', 'required_if:color_scheme,'.ColorScheme::CUSTOM, 'regex:/^#[0-9a-fA-F]{6}$/'],
            'color_gray' => ['required', Rule::in(array_keys(ColorScheme::GRAYS))],
            'color_tint_sidebar' => ['boolean'],
            'media_optimize' => ['boolean'],
            'media_max_side' => ['required', Rule::in(self::MEDIA_MAX_SIDES)],
            'media_quality' => ['required', 'integer', 'between:50,100'],
            'media_format' => ['required', Rule::in(['webp', 'original'])],
            'media_strip_exif' => ['boolean'],
            'media_client_resize' => ['boolean'],
            'media_keep_originals' => ['boolean'],
            'media_storage' => ['required', Rule::in(['public', 's3'])],
            'media_s3_endpoint' => ['nullable', 'url:http,https', 'max:255'],
            'media_s3_region' => ['nullable', 'required_if:media_storage,s3', 'string', 'max:64'],
            'media_s3_bucket' => ['nullable', 'required_if:media_storage,s3', 'string', 'max:255'],
            'media_s3_key' => ['nullable', 'required_if:media_storage,s3', 'string', 'max:255'],
            'media_s3_secret' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->media_storage === 's3' && blank(config('filesystems.disks.s3.secret'))),
                'string',
                'max:1000',
            ],
            'media_s3_path_style' => ['boolean'],
            'media_s3_public_url' => ['nullable', 'url:http,https', 'max:255'],
            'media_s3_prefix' => ['nullable', 'string', 'max:255'],
            'media_max_image_mb' => ['required', 'integer', 'between:1,512', $this->withinServerCap('media.limits.image_mb')],
            'media_max_document_mb' => ['required', 'integer', 'between:1,512', $this->withinServerCap('media.limits.document_mb')],
        ];
    }

    /**
     * Refuses a limit above what PHP accepts (upload_max_filesize, post_max_size), but only when
     * the value differs from the one in effect, so untouched defaults never block a save.
     */
    private function withinServerCap(string $configPath): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($configPath): void {
            if ((int) $value === (int) config($configPath)) {
                return;
            }

            $cap = app(ServerCapabilities::class)->uploadCapBytes();

            if ($cap !== null && (int) $value * 1024 * 1024 > $cap) {
                $fail(__('media.errors.limit_above_server', ['size' => Number::fileSize($cap)]));
            }
        };
    }
}
