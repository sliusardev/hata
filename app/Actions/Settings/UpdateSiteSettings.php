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
     * SMTP fields are removed when the server mailer is chosen, and an empty SMTP password
     * keeps the stored one.
     *
     * @param  array{site_name: string, default_locale: string, timezone: string, registration_enabled: bool, default_role: string|null, mail_mailer: string, mail_host: string|null, mail_port: int|null, mail_scheme: string|null, mail_username: string|null, mail_password: string|null, mail_from_address: string, mail_from_name: string}  $data  Validated input.
     *
     * @throws ProtectedActionException
     */
    public function handle(User $actor, array $data): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if ($data['default_role'] !== null) {
            $role = Role::query()->where('name', $data['default_role'])->first();

            if ($role === null || $role->isSuperAdmin() || ! Gate::forUser($actor)->allows('assign', $role)) {
                throw ProtectedActionException::because('site_settings.messages.role_unavailable');
            }
        }

        if ($data['mail_mailer'] === 'smtp') {
            if (blank($data['mail_password'])) {
                unset($data['mail_password']);
            }
        } else {
            $data['mail_mailer'] = null;
            $data['mail_host'] = null;
            $data['mail_port'] = null;
            $data['mail_scheme'] = null;
            $data['mail_username'] = null;
            $data['mail_password'] = null;
        }

        $this->settings->put($data);
    }
}
