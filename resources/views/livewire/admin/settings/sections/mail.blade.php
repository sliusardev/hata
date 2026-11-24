<?php

use Flux\Flux;
use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new class extends SettingsSectionPage {
    /**
     * The fields that only mean something with the SMTP mailer.
     *
     * @var list<string>
     */
    private const array SMTP_FIELDS = ['mail_host', 'mail_port', 'mail_scheme', 'mail_username'];

    public static function key(): string
    {
        return 'mail';
    }

    protected function fields(): array
    {
        return ['mail_mailer', 'mail_host', 'mail_port', 'mail_scheme', 'mail_username', 'mail_password', 'mail_from_address', 'mail_from_name'];
    }

    protected function secretFields(): array
    {
        return ['mail_password'];
    }

    protected function rules(): array
    {
        return [
            'values.mail_mailer' => ['required', Rule::in(['server', 'smtp'])],
            'values.mail_host' => ['nullable', 'required_if:values.mail_mailer,smtp', 'string', 'max:255'],
            'values.mail_port' => ['nullable', 'required_if:values.mail_mailer,smtp', 'integer', 'between:1,65535'],
            'values.mail_scheme' => ['nullable', 'required_if:values.mail_mailer,smtp', Rule::in(['smtp', 'smtps'])],
            'values.mail_username' => ['nullable', 'string', 'max:255'],
            'values.mail_password' => ['nullable', 'string', 'max:255'],
            'values.mail_from_address' => ['required', 'email', 'max:255'],
            'values.mail_from_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * "server" keeps the mailer from .env, "smtp" uses the SMTP fields, which are shown only then.
     */
    protected function load(): array
    {
        $values = parent::load();
        $smtp = app(SiteSettings::class)->has('mail_mailer');

        $values['mail_mailer'] = $smtp ? 'smtp' : 'server';
        $values['mail_host'] = $smtp ? (string) config('mail.mailers.smtp.host') : '';
        $values['mail_port'] = $smtp ? (string) config('mail.mailers.smtp.port') : '';
        $values['mail_scheme'] = $smtp && config('mail.mailers.smtp.scheme') === 'smtps' ? 'smtps' : 'smtp';
        $values['mail_username'] = $smtp ? (string) config('mail.mailers.smtp.username') : '';

        return $values;
    }

    /**
     * Choosing the SMTP mailer stores the whole connection, not only the fields that differ from the form's blank
     * start, so it never half-follows .env.
     */
    protected function changed(): array
    {
        $changed = parent::changed();

        if (in_array('mail_mailer', $changed, true) && ($this->values['mail_mailer'] ?? '') === 'smtp') {
            $changed = array_values(array_unique([...$changed, ...self::SMTP_FIELDS]));
        }

        return $changed;
    }

    protected function prepare(array $validated): array
    {
        if (array_key_exists('mail_port', $validated) && filled($validated['mail_port'])) {
            $validated['mail_port'] = (int) $validated['mail_port'];
        }

        return parent::prepare($validated);
    }

    protected function persist(array $changed): void
    {
        app(UpdateSiteSettings::class)->handle(Auth::user(), $changed);
    }

    #[Computed]
    public function hasStoredMailPassword(): bool
    {
        return app(SiteSettings::class)->has('mail_password');
    }

    /**
     * Send a test email to the current user with the saved mail settings.
     */
    public function sendTestEmail(): void
    {
        $this->authorizeSection();

        $user = Auth::user();

        try {
            Mail::raw(
                __('hata::site_settings.mail.test_body', ['site' => config('app.name')]),
                fn (Message $message): Message => $message->to($user->email)->subject(__('hata::site_settings.mail.test_subject', ['site' => config('app.name')])),
            );
        } catch (TransportExceptionInterface $exception) {
            Flux::toast(variant: 'danger', text: __('hata::site_settings.mail.test_failed', ['error' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: __('hata::site_settings.mail.test_sent', ['email' => $user->email]));
    }
}; ?>

<x-hata::admin.settings-section :secrets="['mail_password']">
    <div class="grid gap-6 sm:grid-cols-2">
        <flux:input wire:model="values.mail_from_address" type="email" :label="__('hata::site_settings.mail.from_address')" required />
        <flux:input wire:model="values.mail_from_name" :label="__('hata::site_settings.mail.from_name')" required />
    </div>

    <flux:select wire:model.live="values.mail_mailer" :label="__('hata::site_settings.mail.mailer')">
        <flux:select.option value="server">{{ __('hata::site_settings.mail.mailer_server') }}</flux:select.option>
        <flux:select.option value="smtp">{{ __('hata::site_settings.mail.mailer_smtp') }}</flux:select.option>
    </flux:select>

    @if (($values['mail_mailer'] ?? 'server') === 'smtp')
        {{-- The span sits on a wrapper: a class on a Flux field with a label goes to the input, not to the grid cell. --}}
        <div class="space-y-6 rounded-xl border border-zinc-200 p-5 dark:border-white/10" data-test="smtp-fields">
            <div class="grid gap-6 sm:grid-cols-6">
                <div class="sm:col-span-3">
                    <flux:input wire:model="values.mail_host" :label="__('hata::site_settings.mail.host')" placeholder="smtp.example.com" required />
                </div>
                <div class="sm:col-span-1">
                    <flux:input wire:model="values.mail_port" type="number" min="1" max="65535" :label="__('hata::site_settings.mail.port')" placeholder="587" required />
                </div>
                <div class="sm:col-span-2">
                    <flux:select wire:model="values.mail_scheme" :label="__('hata::site_settings.mail.scheme')">
                        <flux:select.option value="smtp">{{ __('hata::site_settings.mail.scheme_starttls') }}</flux:select.option>
                        <flux:select.option value="smtps">{{ __('hata::site_settings.mail.scheme_ssl') }}</flux:select.option>
                    </flux:select>
                </div>
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="values.mail_username" :label="__('hata::site_settings.mail.username')" autocomplete="off" />
                <flux:input
                    wire:model="values.mail_password"
                    type="password"
                    viewable
                    :label="__('hata::site_settings.mail.password')"
                    :description="$this->hasStoredMailPassword ? __('hata::site_settings.mail.password_keep') : null"
                    autocomplete="new-password"
                />
            </div>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">
        <flux:text size="sm" class="max-w-prose flex-1">{{ __('hata::site_settings.mail.send_test_hint') }}</flux:text>
        <flux:button type="button" size="sm" icon="paper-airplane" wire:click="sendTestEmail" data-test="send-test-email-button">
            {{ __('hata::site_settings.mail.send_test') }}
        </flux:button>
    </div>
</x-hata::admin.settings-section>
