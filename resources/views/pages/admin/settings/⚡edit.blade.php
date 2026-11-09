<?php

use App\Actions\Settings\UpdateSiteSettings;
use App\Enums\SystemRole;
use App\Exceptions\ProtectedActionException;
use App\Livewire\Forms\SiteSettingsForm;
use App\Models\Role;
use App\Support\Settings\SiteSettings;
use App\Support\Toast;
use Flux\Flux;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new #[Title('site_settings.title')] class extends Component {
    public SiteSettingsForm $form;

    /**
     * The open tab, one of SiteSettingsForm::SECTIONS.
     */
    #[Url]
    public string $tab = 'general';

    /**
     * The form as it was loaded, to detect unsaved changes when switching tabs.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $savedValues = [];

    /**
     * The tab the user asked for while the open one has unsaved changes.
     */
    #[Locked]
    public string $pendingTab = '';

    public bool $showUnsavedModal = false;

    public function mount(SiteSettings $settings): void
    {
        $this->authorize('settings.edit');

        if (! array_key_exists($this->tab, SiteSettingsForm::SECTIONS)) {
            $this->tab = 'general';
        }

        $this->form->fillFromSettings($settings);
        $this->savedValues = $this->form->all();
    }

    public function updatedTab(): void
    {
        if (! array_key_exists($this->tab, SiteSettingsForm::SECTIONS)) {
            $this->tab = 'general';
        }

        $this->resetErrorBag();
    }

    /**
     * Open another tab, or ask first when the open one has unsaved changes.
     */
    public function switchTab(string $section): void
    {
        if (! array_key_exists($section, SiteSettingsForm::SECTIONS) || $section === $this->tab) {
            return;
        }

        if ($this->form->hasChangesIn($this->tab, $this->savedValues)) {
            $this->pendingTab = $section;
            $this->showUnsavedModal = true;

            return;
        }

        $this->openTab($section);
    }

    /**
     * Drop the unsaved changes of the open tab and go to the requested one.
     */
    public function discardChangesAndSwitch(): void
    {
        $this->form->fill(Arr::only($this->savedValues, SiteSettingsForm::SECTIONS[$this->tab]));
        $this->form->mail_password = '';
        $this->showUnsavedModal = false;

        if (array_key_exists($this->pendingTab, SiteSettingsForm::SECTIONS)) {
            $this->openTab($this->pendingTab);
        }

        $this->pendingTab = '';
    }

    private function openTab(string $section): void
    {
        $this->tab = $section;
        $this->resetErrorBag();
    }

    /**
     * Every role except super-admin; the ones the actor can't assign are disabled (rule 5).
     *
     * @return Collection<int, array{role: Role, assignable: bool}>
     */
    #[Computed]
    public function roleOptions(): Collection
    {
        return Role::query()
            ->with('permissions')
            ->where('name', '!=', SystemRole::SuperAdmin->value)
            ->orderByDesc('is_system')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => ['role' => $role, 'assignable' => Auth::user()->can('assign', $role)]);
    }

    /**
     * Whether everyone who registers would get into the admin panel.
     */
    #[Computed]
    public function defaultRoleGrantsAdminAccess(): bool
    {
        $option = $this->roleOptions->first(fn (array $option): bool => $option['role']->name === $this->form->default_role);

        return $option !== null && $option['role']->hasPermissionTo('admin.access');
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    #[Computed]
    public function hasStoredMailPassword(): bool
    {
        return app(SiteSettings::class)->has('mail_password');
    }

    public function save(UpdateSiteSettings $updateSiteSettings): void
    {
        $this->authorize('settings.edit');

        $data = $this->form->validateSection($this->tab);

        try {
            $updateSiteSettings->handle(Auth::user(), $data);
        } catch (ProtectedActionException $exception) {
            $this->addError('form.default_role', $exception->getMessage());

            return;
        }

        Toast::success(__('site_settings.messages.saved'));

        // A full reload, so the layout picks up the new site name.
        $this->redirectRoute('admin.settings.edit', ['tab' => $this->tab]);
    }

    /**
     * Send a test email to the current user with the saved mail settings.
     */
    public function sendTestEmail(): void
    {
        $this->authorize('settings.edit');

        $user = Auth::user();

        try {
            Mail::raw(
                __('site_settings.mail.test_body', ['site' => config('app.name')]),
                fn (Message $message): Message => $message->to($user->email)->subject(__('site_settings.mail.test_subject', ['site' => config('app.name')])),
            );
        } catch (TransportExceptionInterface $exception) {
            Flux::toast(variant: 'danger', text: __('site_settings.mail.test_failed', ['error' => $exception->getMessage()]));

            return;
        }

        Flux::toast(variant: 'success', text: __('site_settings.mail.test_sent', ['email' => $user->email]));
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('site_settings.title') }}</flux:heading>
        <flux:subheading>{{ __('site_settings.description') }}</flux:subheading>
    </div>

    <flux:navbar scrollable class="-mx-3 border-b border-zinc-200 px-0 dark:border-zinc-700" data-test="site-settings-tabs">
        @foreach (['general' => 'cog-6-tooth', 'access' => 'lock-closed', 'mail' => 'envelope'] as $section => $icon)
            <flux:navbar.item
                :icon="$icon"
                :current="$tab === $section"
                wire:click="switchTab('{{ $section }}')"
                wire:key="tab-{{ $section }}"
                class="cursor-pointer"
            >
                {{ __("site_settings.{$section}.heading") }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>

    <form wire:submit="save" class="space-y-6">
        @if ($tab === 'general')
            <flux:input wire:model="form.site_name" :label="__('site_settings.general.site_name')" :description="__('site_settings.general.site_name_description')" required />

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:select wire:model="form.default_locale" :label="__('site_settings.general.default_locale')" :description="__('site_settings.general.default_locale_description')">
                    @foreach (config('app.available_locales') as $code => $languageName)
                        <flux:select.option :value="$code" wire:key="locale-{{ $code }}">{{ $languageName }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="form.timezone" :label="__('site_settings.general.timezone')" :description="__('site_settings.general.timezone_description')">
                    @foreach ($this->timezones as $timezone)
                        <flux:select.option :value="$timezone" wire:key="tz-{{ $timezone }}">{{ str_replace('_', ' ', $timezone) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @elseif ($tab === 'access')
            <flux:switch wire:model="form.registration_enabled" :label="__('site_settings.access.registration')" :description="__('site_settings.access.registration_description')" align="left" />

            <flux:select wire:model.live="form.default_role" :label="__('site_settings.access.default_role')" :description="__('site_settings.access.default_role_description')">
                <flux:select.option value="">{{ __('site_settings.access.no_role') }}</flux:select.option>
                @foreach ($this->roleOptions as $option)
                    <flux:select.option :value="$option['role']->name" :disabled="! $option['assignable']" wire:key="role-{{ $option['role']->id }}">
                        {{ $option['role']->displayName() }}{{ $option['assignable'] ? '' : ' — '.__('site_settings.access.role_unavailable') }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->defaultRoleGrantsAdminAccess)
                <flux:callout icon="exclamation-triangle" color="amber" :heading="__('site_settings.access.admin_access_warning')" />
            @endif
        @else
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.mail_from_address" type="email" :label="__('site_settings.mail.from_address')" required />
                <flux:input wire:model="form.mail_from_name" :label="__('site_settings.mail.from_name')" required />
            </div>

            <flux:select wire:model.live="form.mail_mailer" :label="__('site_settings.mail.mailer')">
                <flux:select.option value="server">{{ __('site_settings.mail.mailer_server') }}</flux:select.option>
                <flux:select.option value="smtp">{{ __('site_settings.mail.mailer_smtp') }}</flux:select.option>
            </flux:select>

            @if ($form->mail_mailer === 'smtp')
                <div class="grid gap-6 sm:grid-cols-6">
                    <flux:input wire:model="form.mail_host" :label="__('site_settings.mail.host')" placeholder="smtp.example.com" class="sm:col-span-3" required />
                    <flux:input wire:model="form.mail_port" type="number" min="1" max="65535" :label="__('site_settings.mail.port')" placeholder="587" class="sm:col-span-1" required />
                    <flux:select wire:model="form.mail_scheme" :label="__('site_settings.mail.scheme')" class="sm:col-span-2">
                        <flux:select.option value="smtp">{{ __('site_settings.mail.scheme_starttls') }}</flux:select.option>
                        <flux:select.option value="smtps">{{ __('site_settings.mail.scheme_ssl') }}</flux:select.option>
                    </flux:select>
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="form.mail_username" :label="__('site_settings.mail.username')" autocomplete="off" />
                    <flux:input
                        wire:model="form.mail_password"
                        type="password"
                        viewable
                        :label="__('site_settings.mail.password')"
                        :description="$this->hasStoredMailPassword ? __('site_settings.mail.password_keep') : null"
                        autocomplete="new-password"
                    />
                </div>
            @endif
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if ($tab === 'mail')
                    <flux:button variant="ghost" icon="paper-airplane" wire:click="sendTestEmail" data-test="send-test-email-button">
                        {{ __('site_settings.mail.send_test') }}
                    </flux:button>
                @endif
            </div>

            <flux:button variant="primary" type="submit" data-test="save-site-settings-button">
                {{ __('common.actions.save') }}
            </flux:button>
        </div>

        @if ($tab === 'mail')
            <flux:text class="text-xs">{{ __('site_settings.mail.send_test_hint') }}</flux:text>
        @endif
    </form>

    <x-confirm-modal
        wire:model.self="showUnsavedModal"
        :heading="__('site_settings.unsaved.heading')"
        :confirm-label="__('site_settings.unsaved.discard')"
        confirm-action="discardChangesAndSwitch"
    >
        {{ __('site_settings.unsaved.body', ['tab' => __("site_settings.{$tab}.heading")]) }}
    </x-confirm-modal>
</section>
