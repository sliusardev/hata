<?php

namespace Hata\Actions\Settings;

use App\Models\User;
use Hata\Exceptions\ProtectedActionException;
use Hata\Exceptions\StorageSettingsException;
use Hata\Livewire\Forms\SiteSettingsForm;
use Hata\Models\Media;
use Hata\Models\Role;
use Hata\Support\Media\MediaDisks;
use Hata\Support\Settings\SiteSettings;
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
                throw ProtectedActionException::because('hata::site_settings.messages.role_unavailable');
            }
        }

        if (array_key_exists('mail_mailer', $data)) {
            $data = $this->normalizeMail($data);
        }

        if (array_intersect_key($data, array_flip(SiteSettingsForm::STORAGE_FIELDS)) !== []) {
            $data = $this->normalizeStorage($data);
        }

        $this->settings->put($data);
    }

    /**
     * `$data` holds only the storage fields the admin changed (the page leaves the untouched ones out, so they keep
     * following .env). An empty secret keeps the stored one. The connection can't be cleared while files are on s3,
     * and a change to the storage or its connection is tested first when s3 is (or still holds files) in use.
     *
     * @param  array<string, string|int|bool|null>  $data
     * @return array<string, string|int|bool|null>
     *
     * @throws StorageSettingsException
     */
    private function normalizeStorage(array $data): array
    {
        if (blank($data['media_s3_secret'] ?? null)) {
            unset($data['media_s3_secret']);
        }

        // The S3 connection settings belong to the `s3` disk, so rows on that disk depend on them.
        $filesOnS3 = Media::query()->where('disk', 's3')->exists();

        if ($filesOnS3) {
            foreach (['media_s3_bucket', 'media_s3_key'] as $field) {
                if (array_key_exists($field, $data) && blank($data[$field])) {
                    throw StorageSettingsException::on($field, __('hata::site_settings.media.storage.errors.cannot_clear'));
                }
            }
        }

        $effective = [...$this->settingsInEffect(), ...$data];

        // Switching back to local is never blocked by a broken connection: only a changed connection field,
        // or a switch to s3, is tested.
        $connectionChanged = array_intersect_key($data, array_flip(array_diff(SiteSettingsForm::STORAGE_FIELDS, ['media_storage']))) !== [];
        $switchedToS3 = ($data['media_storage'] ?? null) === 's3';

        if (($effective['media_storage'] === 's3' || $filesOnS3) && ($connectionChanged || $switchedToS3)) {
            $result = $this->connectionTest->handle(MediaDisks::connectionConfig($effective));

            if (! $result['ok']) {
                throw StorageSettingsException::on('media_storage', __('hata::site_settings.media.storage.not_saved', ['reason' => $result['message'] ?? '']));
            }
        }

        return $data;
    }

    /**
     * The storage settings as they are now (stored over .env), keyed like the settings.
     *
     * @return array<string, mixed>
     */
    private function settingsInEffect(): array
    {
        return [
            'media_storage' => config('hata.media.disk'),
            'media_s3_endpoint' => config('filesystems.disks.s3.endpoint'),
            'media_s3_region' => config('filesystems.disks.s3.region'),
            'media_s3_bucket' => config('filesystems.disks.s3.bucket'),
            'media_s3_key' => config('filesystems.disks.s3.key'),
            'media_s3_path_style' => config('filesystems.disks.s3.use_path_style_endpoint'),
            'media_s3_prefix' => config('filesystems.disks.s3.root'),
        ];
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
