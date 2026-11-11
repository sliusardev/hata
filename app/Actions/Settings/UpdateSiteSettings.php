<?php

namespace App\Actions\Settings;

use App\Exceptions\ProtectedActionException;
use App\Exceptions\StorageSettingsException;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Support\Media\MediaDisks;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Gate;

class UpdateSiteSettings
{
    public function __construct(private SiteSettings $settings, private TestStorageConnection $connectionTest) {}

    /**
     * Store the settings of one tab: only the given keys change. SMTP fields are removed
     * when the server mailer is chosen, and an empty SMTP password keeps the stored one.
     *
     * @param  array<string, string|int|bool|null>  $data  Validated input.
     *
     * @throws ProtectedActionException|StorageSettingsException
     */
    public function handle(User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if (filled($data['default_role'] ?? null)) {
            $role = Role::query()->where('name', $data['default_role'])->first();

            if ($role === null || $role->isSuperAdmin() || ! Gate::forUser($actor)->allows('assign', $role)) {
                throw ProtectedActionException::because('site_settings.messages.role_unavailable');
            }
        }

        if (array_key_exists('mail_mailer', $data)) {
            $data = $this->normalizeMail($data);
        }

        if (array_key_exists('media_storage', $data)) {
            $data = $this->normalizeStorage($data);
        }

        $this->settings->put($data);
    }

    /**
     * An empty secret keeps the stored one. The connection can't be cleared while files are on s3, and choosing s3
     * needs a passing connection test with the values being saved.
     *
     * @param  array<string, string|int|bool|null>  $data
     * @return array<string, string|int|bool|null>
     *
     * @throws StorageSettingsException
     */
    private function normalizeStorage(array $data): array
    {
        $typedSecret = $data['media_s3_secret'] ?? null;

        if (blank($typedSecret)) {
            unset($data['media_s3_secret']);
        }

        if (Media::query()->where('disk', 's3')->exists()) {
            foreach (['media_s3_bucket', 'media_s3_key'] as $field) {
                if (array_key_exists($field, $data) && blank($data[$field])) {
                    throw StorageSettingsException::on($field, __('site_settings.media.storage.errors.cannot_clear'));
                }
            }
        }

        if (($data['media_storage'] ?? null) === 's3') {
            $result = $this->connectionTest->handle(MediaDisks::connectionConfig($data));

            if (! $result['ok']) {
                throw StorageSettingsException::on('media_storage', __('site_settings.media.storage.not_saved', ['reason' => $result['message'] ?? '']));
            }
        }

        return $data;
    }

    /**
     * @param  array<string, string|int|bool|null>  $data
     * @return array<string, string|int|bool|null>
     */
    private function normalizeMail(array $data): array
    {
        if ($data['mail_mailer'] === 'smtp') {
            if (blank($data['mail_password'] ?? null)) {
                unset($data['mail_password']);
            }

            return $data;
        }

        return [
            ...$data,
            'mail_mailer' => null,
            'mail_host' => null,
            'mail_port' => null,
            'mail_scheme' => null,
            'mail_username' => null,
            'mail_password' => null,
        ];
    }
}
