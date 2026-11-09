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
     * Fields of each settings tab, saved independently of each other.
     *
     * @var array<string, list<string>>
     */
    public const array SECTIONS = [
        'general' => ['site_name', 'default_locale', 'timezone'],
        'access' => ['registration_enabled', 'default_role'],
        'mail' => ['mail_mailer', 'mail_host', 'mail_port', 'mail_scheme', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'],
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
            if ($field === 'mail_password') {
                if (filled($this->mail_password)) {
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
                $field === 'registration_enabled' => (bool) $value,
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
        ];
    }
}
