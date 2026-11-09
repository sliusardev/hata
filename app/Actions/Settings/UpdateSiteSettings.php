<?php

namespace App\Actions\Settings;

use App\Exceptions\ProtectedActionException;
use App\Models\Role;
use App\Models\User;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Gate;

class UpdateSiteSettings
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Store the settings of one tab: only the given keys change. SMTP fields are removed
     * when the server mailer is chosen, and an empty SMTP password keeps the stored one.
     *
     * @param  array<string, string|int|bool|null>  $data  Validated input.
     *
     * @throws ProtectedActionException
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

        $this->settings->put($data);
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
