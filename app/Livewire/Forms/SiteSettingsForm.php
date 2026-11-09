<?php

namespace App\Livewire\Forms;

use App\Enums\SystemRole;
use App\Support\Settings\SiteSettings;
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
     * Fill the form with the values in effect (stored settings over .env).
     */
    public function fillFromSettings(SiteSettings $settings): void
    {
        $this->site_name = (string) config('app.name');
        $this->default_locale = (string) config('app.locale');
        $this->timezone = (string) config('admin.timezone');
        $this->registration_enabled = (bool) config('admin.registration_enabled');
        $this->default_role = (string) config('admin.default_role');
        $this->mail_mailer = $settings->has('mail_mailer') ? 'smtp' : 'server';
        $this->mail_from_address = (string) config('mail.from.address');
        $this->mail_from_name = (string) config('mail.from.name');

        if ($this->mail_mailer === 'smtp') {
            $this->mail_host = (string) config('mail.mailers.smtp.host');
            $this->mail_port = (string) config('mail.mailers.smtp.port');
            $this->mail_scheme = config('mail.mailers.smtp.scheme') === 'smtps' ? 'smtps' : 'smtp';
            $this->mail_username = (string) config('mail.mailers.smtp.username');
        }
    }

    /**
     * @return array{site_name: string, default_locale: string, timezone: string, registration_enabled: bool, default_role: string|null, mail_mailer: string, mail_host: string|null, mail_port: int|null, mail_scheme: string|null, mail_username: string|null, mail_password: string|null, mail_from_address: string, mail_from_name: string}
     */
    public function validateForSave(): array
    {
        /** @var array{site_name: string, default_locale: string, timezone: string, registration_enabled: bool, default_role: string|null, mail_mailer: string, mail_host: string|null, mail_port: int|string|null, mail_scheme: string|null, mail_username: string|null, mail_password: string|null, mail_from_address: string, mail_from_name: string} $validated */
        $validated = $this->validate([
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
        ]);

        return [
            'site_name' => $validated['site_name'],
            'default_locale' => $validated['default_locale'],
            'timezone' => $validated['timezone'],
            'registration_enabled' => (bool) $validated['registration_enabled'],
            'default_role' => filled($validated['default_role'] ?? null) ? $validated['default_role'] : null,
            'mail_mailer' => $validated['mail_mailer'],
            'mail_host' => filled($validated['mail_host'] ?? null) ? $validated['mail_host'] : null,
            'mail_port' => filled($validated['mail_port'] ?? null) ? (int) $validated['mail_port'] : null,
            'mail_scheme' => filled($validated['mail_scheme'] ?? null) ? $validated['mail_scheme'] : null,
            'mail_username' => filled($validated['mail_username'] ?? null) ? $validated['mail_username'] : null,
            'mail_password' => filled($validated['mail_password'] ?? null) ? $validated['mail_password'] : null,
            'mail_from_address' => $validated['mail_from_address'],
            'mail_from_name' => $validated['mail_from_name'],
        ];
    }
}
