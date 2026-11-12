<?php

use App\Actions\Media\CreateStorageLink;
use App\Actions\Media\OptimizeImage;
use App\Actions\Settings\RemoveLogo;
use App\Actions\Settings\UpdateSiteSettings;
use App\Actions\Settings\SetLogo;
use App\Actions\Settings\TestStorageConnection;
use App\Enums\SystemRole;
use App\Exceptions\ProtectedActionException;
use App\Exceptions\StorageLinkException;
use App\Exceptions\StorageSettingsException;
use App\Livewire\Forms\SiteSettingsForm;
use App\Support\ColorScheme\ColorScheme;
use App\Models\Media;
use App\Models\Role;
use App\Support\Media\DirectServing;
use App\Support\Media\MediaDisks;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use App\Support\Media\ServerCapabilities;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use App\Support\Toast;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

new #[Title('site_settings.title')] class extends Component {
    public SiteSettingsForm $form;

    /**
     * The light-theme logo's media id, bound to the media picker (null = none). A chosen file is stored as soon as it is picked.
     */
    public mixed $logoLight = null;

    /**
     * The dark-theme logo's media id, bound to the media picker (null = none).
     */
    public mixed $logoDark = null;

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

    /**
     * Asks for confirmation when the bucket, endpoint, prefix or public URL change while files are on s3.
     */
    public bool $showS3ChangeModal = false;

    /**
     * The last "Test connection" result, null until one runs or after a connection field changes.
     *
     * @var array{ok: bool, message: string}|null
     */
    public ?array $storageTest = null;

    public function mount(SiteSettings $settings): void
    {
        $this->authorize('settings.edit');

        if (! array_key_exists($this->tab, SiteSettingsForm::SECTIONS)) {
            $this->tab = 'general';
        }

        $this->form->fillFromSettings($settings);
        $this->logoLight = SiteLogo::own('light')?->id;
        $this->logoDark = SiteLogo::own('dark')?->id;
        $this->savedValues = $this->form->all();
    }

    /**
     * A test result describes the values it ran with, so it goes away when they change.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'form.media_s3_') || $name === 'form.media_storage') {
            $this->storageTest = null;
        }
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
        $this->form->media_s3_secret = '';
        $this->storageTest = null;
        $this->showUnsavedModal = false;

        if (array_key_exists($this->pendingTab, SiteSettingsForm::SECTIONS)) {
            $this->openTab($this->pendingTab);
        }

        $this->pendingTab = '';
    }

    public function updatedLogoLight(SetLogo $setLogo, RemoveLogo $removeLogo): void
    {
        $this->applyLogo($setLogo, $removeLogo, 'light');
    }

    public function updatedLogoDark(SetLogo $setLogo, RemoveLogo $removeLogo): void
    {
        $this->applyLogo($setLogo, $removeLogo, 'dark');
    }

    public function removeLogo(RemoveLogo $removeLogo, string $variant): void
    {
        $this->authorize('settings.edit');

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            return;
        }

        $removeLogo->handle(Auth::user(), $variant);

        Toast::success(__('site_settings.logo.removed'));

        $this->redirectRoute('admin.settings.edit', ['tab' => 'logo']);
    }

    /**
     * The file chosen for each variant (no fallback to the other one), for the previews.
     *
     * @return array<string, Media|null>
     */
    #[Computed]
    public function chosenLogos(): array
    {
        return ['light' => SiteLogo::own('light'), 'dark' => SiteLogo::own('dark')];
    }

    /**
     * Whether the variant's setting holds a media id whose file is gone (row deleted, file missing or not a logo):
     * the picker shows no choice then, so the slot offers its own "Remove".
     */
    public function logoIsMissing(string $variant): bool
    {
        return is_int(config("admin.logo.{$variant}")) && $this->chosenLogos[$variant] === null;
    }

    /**
     * The scheme as currently chosen in the form, applied to the page before it is saved.
     */
    #[Computed]
    public function previewCss(): string
    {
        return $this->schemeFromForm()->css(includeStock: true);
    }

    /**
     * Swatches of every option: light accent, dark accent and the light sidebar.
     *
     * @return array<string, array{accent: string, dark: string, sidebar: string}>
     */
    #[Computed]
    public function schemeSwatches(): array
    {
        $swatches = [];

        foreach ([...array_keys(ColorScheme::PRESETS), ColorScheme::CUSTOM] as $key) {
            $scheme = $key === ColorScheme::CUSTOM
                ? ColorScheme::resolve($key, $this->form->color_accent, $this->form->color_gray, true)
                : ColorScheme::resolve($key, null, null, true);
            $variables = $scheme->variables(includeStock: true);

            $swatches[$key] = [
                'accent' => $variables['light']['--color-accent'],
                'dark' => $variables['dark']['--color-sidebar'],
                'sidebar' => $variables['light']['--color-sidebar'],
            ];
        }

        return $swatches;
    }

    private function schemeFromForm(): ColorScheme
    {
        return ColorScheme::resolve(
            $this->form->color_scheme,
            $this->form->color_accent,
            $this->form->color_gray,
            $this->form->color_tint_sidebar,
        );
    }

    /**
     * Store the file the picker sent (or stop using the logo when it sent none), then reload so every layout shows it.
     */
    private function applyLogo(SetLogo $setLogo, RemoveLogo $removeLogo, string $variant): void
    {
        $this->authorize('settings.edit');

        $property = $variant === 'light' ? 'logoLight' : 'logoDark';
        $value = $this->{$property};
        $stored = config("admin.logo.{$variant}");
        $stored = is_int($stored) ? $stored : null;

        // The picker echoes its value when it opens, so an empty value on an empty slot, or the stored id, is no change.
        if (blank($value) && $value !== 0 && $value !== '0') {
            // A dangling setting (its file is gone) shows an empty picker, so an empty echo must not clear it.
            if (SiteLogo::own($variant) === null) {
                $this->{$property} = null;

                return;
            }

            $this->removeLogo($removeLogo, $variant);

            return;
        }

        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if ($value === $stored) {
            $this->{$property} = $stored;

            return;
        }

        try {
            if (! is_int($value)) {
                throw new AuthorizationException;
            }

            $setLogo->handle(Auth::user(), $variant, $value);
        } catch (ValidationException|AuthorizationException) {
            $this->{$property} = SiteLogo::own($variant)?->id;
            $this->addError($property, __('media.picker.invalid'));

            return;
        }

        Toast::success(__('site_settings.logo.chosen'));

        $this->redirectRoute('admin.settings.edit', ['tab' => 'logo']);
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
    public function avifSupported(): bool
    {
        return app(ServerCapabilities::class)->supportsAvif();
    }

    /**
     * The server upload cap as text, null when PHP sets none.
     */
    #[Computed]
    public function serverCap(): ?string
    {
        $bytes = app(ServerCapabilities::class)->uploadCapBytes();

        return $bytes === null ? null : Number::fileSize($bytes);
    }

    /**
     * The "optimize existing files" card: the run in progress, or how many stored images don't match the saved settings
     * and a rough saving (85% of the size of never-optimized files, 20% of the rest). Two SQL sums, no rows loaded;
     * only read by the Media tab.
     *
     * @return array{enabled: bool, run: OptimizationRun|null, count: int, saving: string}
     */
    #[Computed]
    public function optimizationCard(): array
    {
        if (! config('media.optimize.enabled')) {
            return ['enabled' => false, 'run' => null, 'count' => 0, 'saving' => ''];
        }

        $fingerprint = app(OptimizeImage::class)->fingerprint();
        $run = app(OptimizationRunStore::class)->get();

        if ($run !== null && in_array($run->status, ['running', 'paused'], true) && ! $run->isStale($fingerprint)) {
            return ['enabled' => true, 'run' => $run, 'count' => 0, 'saving' => ''];
        }

        $totals = Media::query()
            ->optimizationCandidates($fingerprint)
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when optimized_with is null then size else 0 end), 0) as fresh_size')
            ->selectRaw('coalesce(sum(case when optimized_with is null then 0 else size end), 0) as old_size')
            ->first();

        $saving = (int) round((float) $totals->fresh_size * 0.85 + (float) $totals->old_size * 0.20);

        return ['enabled' => true, 'run' => null, 'count' => (int) $totals->total, 'saving' => Media::formatBytes($saving)];
    }

    #[Computed]
    public function hasStoredS3Secret(): bool
    {
        return app(SiteSettings::class)->has('media_s3_secret');
    }

    /**
     * A secret key that comes from the server configuration (.env) rather than from a saved setting.
     */
    #[Computed]
    public function hasEnvS3Secret(): bool
    {
        return ! $this->hasStoredS3Secret && filled(config('filesystems.disks.s3.secret'));
    }

    #[Computed]
    public function hasStoredMailPassword(): bool
    {
        return app(SiteSettings::class)->has('mail_password');
    }

    public function save(UpdateSiteSettings $updateSiteSettings): void
    {
        $this->persist($updateSiteSettings, false);
    }

    /**
     * Save after the admin confirmed that the existing s3 files are already at the new location.
     */
    public function confirmS3Change(UpdateSiteSettings $updateSiteSettings): void
    {
        $this->showS3ChangeModal = false;

        $this->persist($updateSiteSettings, true);
    }

    private function persist(UpdateSiteSettings $updateSiteSettings, bool $s3ChangeConfirmed): void
    {
        $this->authorize('settings.edit');

        $data = $this->form->validateSection($this->tab);

        if ($this->tab === 'media' && ! $s3ChangeConfirmed && $this->movesS3Files()) {
            $this->showS3ChangeModal = true;

            return;
        }

        if ($this->tab === 'media') {
            // Store a storage field only when the admin changed it; untouched ones keep following .env.
            foreach (SiteSettingsForm::STORAGE_FIELDS as $field) {
                if ($field !== 'media_s3_secret' && (string) $this->form->{$field} === (string) ($this->savedValues[$field] ?? '')) {
                    unset($data[$field]);
                }
            }
        }

        try {
            $updateSiteSettings->handle(Auth::user(), $data);
        } catch (StorageSettingsException $exception) {
            $this->addError("form.{$exception->field}", $exception->getMessage());

            return;
        } catch (ProtectedActionException $exception) {
            $this->addError('form.default_role', $exception->getMessage());

            return;
        }

        // Mark the form clean, so the leave-page warning doesn't fire on the redirect below.
        $this->form->mail_password = '';
        $this->form->media_s3_secret = '';
        $this->savedValues = $this->form->all();

        Toast::success(__('site_settings.messages.saved'));

        // A full reload, so the layout picks up the new site name.
        $this->redirectRoute('admin.settings.edit', ['tab' => $this->tab]);
    }

    /**
     * Whether the form changes where existing s3 files are looked up (bucket, endpoint, prefix, public URL)
     * while some media rows are on s3.
     */
    private function movesS3Files(): bool
    {
        $changed = false;

        foreach (['media_s3_bucket', 'media_s3_endpoint', 'media_s3_prefix', 'media_s3_public_url'] as $field) {
            $changed = $changed || trim((string) $this->form->{$field}, '/') !== trim((string) ($this->savedValues[$field] ?? ''), '/');
        }

        // A cleared bucket or key is refused by the action; no point asking first.
        if (blank($this->form->media_s3_bucket) || blank($this->form->media_s3_key)) {
            return false;
        }

        // The connection settings belong to the `s3` disk.
        return $changed && $this->hasS3Files;
    }

    /**
     * Whether any media row is on the `s3` disk, the one the connection settings configure.
     */
    #[Computed]
    public function hasS3Files(): bool
    {
        return Media::query()->where('disk', 's3')->exists();
    }

    /**
     * Number of media files on a disk other than the one new uploads go to.
     */
    #[Computed]
    public function filesOnOtherDisk(): int
    {
        return Media::query()->where('disk', '!=', config('media.disk'))->count();
    }

    /**
     * Whether the saved local storage is served as static files, one of the DirectServing states.
     */
    #[Computed]
    public function directState(): string
    {
        return app(DirectServing::class)->state();
    }

    /**
     * Create the `public/storage` link, then probe it in the browser so the status line settles right away.
     */
    public function createStorageLink(CreateStorageLink $createStorageLink): void
    {
        try {
            $createStorageLink->handle(Auth::user());
        } catch (StorageLinkException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->directState);
        Flux::toast(variant: 'success', text: __('site_settings.media.storage.direct.link_created'));
        $this->js('window.checkMediaDirect().then(() => $wire.$refresh())');
    }

    /**
     * Try the storage with the values in the form (saved or not).
     */
    public function testStorageConnection(TestStorageConnection $connectionTest): void
    {
        $this->authorize('settings.edit');

        $limiterKey = 'storage-test:'.Auth::id();

        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            $this->storageTest = [
                'ok' => false,
                'message' => __('site_settings.media.storage.errors.rate_limited', ['seconds' => RateLimiter::availableIn($limiterKey)]),
            ];

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $result = $connectionTest->handle(MediaDisks::connectionConfig([
            'media_s3_endpoint' => $this->form->media_s3_endpoint,
            'media_s3_region' => $this->form->media_s3_region,
            'media_s3_bucket' => $this->form->media_s3_bucket,
            'media_s3_key' => $this->form->media_s3_key,
            'media_s3_secret' => $this->form->media_s3_secret,
            'media_s3_path_style' => $this->form->media_s3_path_style,
            'media_s3_prefix' => $this->form->media_s3_prefix,
        ]));

        $this->storageTest = [
            'ok' => $result['ok'],
            'message' => $result['ok'] ? __('site_settings.media.storage.test_ok') : ($result['message'] ?? ''),
        ];
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

<section
    class="w-full max-w-3xl space-y-6"
    x-data="{
        fields: @js(array_merge(...array_values(App\Livewire\Forms\SiteSettingsForm::SECTIONS))),
        pendingUrl: null,
        leaving: false,
        isDirty() {
            const form = $wire.form;
            const saved = $wire.savedValues;

            return this.fields.some((field) => ['mail_password', 'media_s3_secret'].includes(field)
                ? (form[field] ?? '') !== ''
                : String(form[field] ?? '') !== String(saved[field] ?? ''));
        },
        leave() {
            this.leaving = true;
            $flux.modal('leave-site-settings').close();
            Livewire.navigate(this.pendingUrl);
        },
    }"
    x-on:livewire:navigate.document="if (! leaving && ! $event.detail.history && isDirty()) { $event.preventDefault(); pendingUrl = $event.detail.url.toString(); $flux.modal('leave-site-settings').show(); }"
    x-on:beforeunload.window="if (! leaving && isDirty()) { $event.preventDefault(); $event.returnValue = ''; }"
>
    <div>
        <flux:heading size="xl" level="1">{{ __('site_settings.title') }}</flux:heading>
        <flux:subheading>{{ __('site_settings.description') }}</flux:subheading>
    </div>

    <flux:navbar scrollable class="-mx-3 border-b border-zinc-200 px-0 dark:border-zinc-700" data-test="site-settings-tabs">
        @foreach (['general' => 'cog-6-tooth', 'access' => 'lock-closed', 'mail' => 'envelope', 'logo' => 'photo', 'colors' => 'swatch', 'media' => 'adjustments-horizontal'] as $section => $icon)
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
        @elseif ($tab === 'colors')
            {{-- Live preview: overrides the saved scheme from <head> while this tab is open. --}}
            <style>{!! $this->previewCss !!}</style>

            <flux:text>{{ __('site_settings.colors.description') }}</flux:text>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('site_settings.colors.heading') }}">
                @foreach ($this->schemeSwatches as $key => $swatch)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $form->color_scheme === $key ? 'true' : 'false' }}"
                        wire:click="$set('form.color_scheme', '{{ $key }}')"
                        wire:key="scheme-{{ $key }}"
                        data-test="color-scheme-{{ $key }}"
                        @class([
                            'flex cursor-pointer flex-col gap-2 rounded-lg border p-3 text-start transition',
                            'border-accent ring-2 ring-accent' => $form->color_scheme === $key,
                            'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => $form->color_scheme !== $key,
                        ])
                    >
                        <span class="flex items-center gap-1">
                            <span class="size-5 rounded-full border border-black/10" style="background: {{ $swatch['accent'] }}"></span>
                            <span class="size-5 rounded-full border border-black/10" style="background: {{ $swatch['sidebar'] }}"></span>
                            <span class="size-5 rounded-full border border-white/10" style="background: {{ $swatch['dark'] }}"></span>
                        </span>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __("site_settings.colors.schemes.{$key}") }}</span>
                    </button>
                @endforeach
            </div>
            <flux:error name="form.color_scheme" />

            @if ($form->color_scheme === App\Support\ColorScheme\ColorScheme::CUSTOM)
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('site_settings.colors.accent') }}</flux:label>
                        <flux:description>{{ __('site_settings.colors.accent_description') }}</flux:description>
                        <div class="flex items-center gap-2">
                            <input
                                type="color"
                                wire:model.live.debounce.200ms="form.color_accent"
                                aria-label="{{ __('site_settings.colors.accent') }}"
                                class="h-10 w-14 shrink-0 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-600 dark:bg-zinc-700"
                            />
                            <flux:input wire:model.live.blur="form.color_accent" placeholder="#2563eb" maxlength="7" class="font-mono" />
                        </div>
                        <flux:error name="form.color_accent" />
                    </flux:field>

                    <flux:select wire:model.live="form.color_gray" :label="__('site_settings.colors.gray')" :description="__('site_settings.colors.gray_description')">
                        @foreach (array_keys(App\Support\ColorScheme\ColorScheme::GRAYS) as $gray)
                            <flux:select.option :value="$gray" wire:key="gray-{{ $gray }}">{{ __("site_settings.colors.grays.{$gray}") }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @unless ($form->color_scheme === 'neutral')
                <flux:switch wire:model.live="form.color_tint_sidebar" :label="__('site_settings.colors.tint_sidebar')" :description="__('site_settings.colors.tint_sidebar_description')" align="left" />
            @endunless

            <flux:callout icon="eye" variant="secondary" :heading="__('site_settings.colors.preview_hint')" />
        @elseif ($tab === 'logo')
            <flux:text>{{ __('site_settings.logo.description') }}</flux:text>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (['light' => 'logoLight', 'dark' => 'logoDark'] as $variant => $property)
                    <div class="space-y-3" wire:key="logo-{{ $variant }}">
                        <flux:heading>{{ __("site_settings.logo.{$variant}") }}</flux:heading>

                        <div @class([
                            'flex h-24 items-center justify-center rounded-lg border px-4',
                            'border-zinc-200 bg-zinc-50' => $variant === 'light',
                            'border-zinc-700 bg-zinc-900' => $variant === 'dark',
                        ])>
                            @if ($this->chosenLogos[$variant])
                                <img src="{{ $this->chosenLogos[$variant]->url() }}" alt="" class="h-12 w-auto max-w-full object-contain" data-test="logo-preview-{{ $variant }}" />
                            @else
                                <div class="text-center">
                                    @if (SiteLogo::isCustom())
                                        <img src="{{ SiteLogo::url($variant) }}" alt="" class="mx-auto h-12 w-auto max-w-full object-contain opacity-60" />
                                    @else
                                        <span @class([
                                            'mx-auto flex size-12 items-center justify-center rounded-md',
                                            'bg-neutral-800 text-white' => $variant === 'light',
                                            'bg-white text-neutral-800' => $variant === 'dark',
                                        ])>
                                            <x-app-logo-icon class="size-[72%]" />
                                        </span>
                                    @endif
                                    <flux:text class="mt-1 text-xs {{ $variant === 'dark' ? 'text-zinc-400!' : '' }}">
                                        {{ SiteLogo::isCustom() ? __('site_settings.logo.uses_other') : __('site_settings.logo.default') }}
                                    </flux:text>
                                </div>
                            @endif
                        </div>

                        @if ($this->logoIsMissing($variant))
                            <div class="flex items-center gap-3" data-test="logo-missing-{{ $variant }}">
                                <flux:text size="sm" class="flex-1">{{ __('site_settings.logo.missing') }}</flux:text>
                                <flux:button size="sm" variant="ghost" wire:click="removeLogo('{{ $variant }}')">{{ __('site_settings.logo.remove_missing') }}</flux:button>
                            </div>
                        @endif

                        <livewire:media-picker
                            wire:model.live="{{ $property }}"
                            accept="image"
                            :extensions="SiteLogo::EXTENSIONS"
                            :label="__('site_settings.logo.'.$variant)"
                            :key="'logo-picker-'.$variant"
                        />
                        <flux:error :name="$property" />
                    </div>
                @endforeach
            </div>

            <flux:text class="text-xs">{{ __('site_settings.logo.requirements') }}</flux:text>
        @elseif ($tab === 'media')
            <flux:heading level="2">{{ __('site_settings.media.optimize_section') }}</flux:heading>

            <flux:switch wire:model.live="form.media_optimize" :label="__('site_settings.media.optimize')" :description="__('site_settings.media.optimize_description')" align="left" />

            @if ($form->media_optimize)
                <flux:select wire:model="form.media_max_side" :label="__('site_settings.media.max_side')" :description="__('site_settings.media.max_side_description')" class="max-w-xs">
                    @foreach (App\Livewire\Forms\SiteSettingsForm::MEDIA_MAX_SIDES as $side)
                        <flux:select.option :value="$side" wire:key="max-side-{{ $side }}">{{ __("site_settings.media.max_side_{$side}") }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:field x-data="{ quality: $wire.form.media_quality }">
                    <div class="flex items-center justify-between">
                        <label for="media-quality" class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('site_settings.media.quality') }}</label>
                        <span class="text-sm font-medium tabular-nums text-zinc-800 dark:text-white" data-test="media-quality-value" x-text="quality">{{ $form->media_quality }}</span>
                    </div>
                    <input id="media-quality" type="range" min="50" max="100" step="1" wire:model="form.media_quality" x-model="quality" class="w-full accent-accent" />
                    <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                        <span>{{ __('site_settings.media.quality_smaller') }}</span>
                        <span>{{ __('site_settings.media.quality_better') }}</span>
                    </div>
                    <flux:error name="form.media_quality" />
                </flux:field>

                <flux:radio.group wire:model="form.media_format" :label="__('site_settings.media.format')">
                    <flux:radio value="webp" :label="__('site_settings.media.format_webp')" :description="__('site_settings.media.format_webp_description')" />
                    <flux:radio value="original" :label="__('site_settings.media.format_original')" />
                    {{-- AVIF is display-only for now: OptimizeImage accepts webp and original. --}}
                    <flux:radio
                        value="avif"
                        disabled
                        :label="__('site_settings.media.format_avif')"
                        :description="$this->avifSupported ? __('site_settings.media.format_avif_later') : __('site_settings.media.format_avif_unsupported')"
                    />
                </flux:radio.group>

                <flux:switch wire:model="form.media_strip_exif" :label="__('site_settings.media.strip_exif')" :description="__('site_settings.media.strip_exif_description')" align="left" />
                <flux:switch wire:model="form.media_client_resize" :label="__('site_settings.media.client_resize')" :description="__('site_settings.media.client_resize_description')" align="left" />
                <flux:switch wire:model="form.media_keep_originals" :label="__('site_settings.media.keep_originals')" :description="__('site_settings.media.keep_originals_description')" align="left" />
                @can('media.edit')
                    @php($card = $this->optimizationCard)
                    <flux:callout icon="arrow-path" class="max-w-xl" data-test="optimize-existing-card">
                        <flux:callout.heading>{{ __('site_settings.media.existing_heading') }}</flux:callout.heading>
                        <flux:callout.text>
                            @if (! $card['enabled'])
                                <span data-test="optimize-existing-unsaved">{{ __('site_settings.media.existing_save_first') }}</span>
                            @elseif ($card['run'] !== null)
                                <span data-test="optimize-existing-progress">{{ __("media.optimize.status.{$card['run']->status}") }}: {{ trans_choice('media.batch.progress', $card['run']->total, ['processed' => $card['run']->processed(), 'total' => $card['run']->total]) }}</span>
                            @elseif ($card['count'] > 0)
                                <span><span data-test="optimize-existing-count">{{ $card['count'] }}</span> {{ trans_choice('site_settings.media.existing_candidates', $card['count']) }}</span>
                                <span data-test="optimize-existing-saving">{{ __('site_settings.media.existing_saving', ['size' => $card['saving']]) }}</span>
                            @else
                                {{ __('site_settings.media.existing_none') }}
                            @endif
                        </flux:callout.text>
                        <x-slot name="actions">
                            @if ($card['enabled'])<flux:button size="sm" :href="route('admin.media.optimize')" wire:navigate>{{ __('site_settings.media.existing_button') }}</flux:button>@endif
                        </x-slot>
                    </flux:callout>
                @endcan
            @endif

            <flux:separator />

            <flux:heading level="2">{{ __('site_settings.media.limits_section') }}</flux:heading>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="form.media_max_image_mb" type="number" min="1" max="512" :label="__('site_settings.media.max_image_mb')" required />
                <flux:input wire:model="form.media_max_document_mb" type="number" min="1" max="512" :label="__('site_settings.media.max_document_mb')" required />
            </div>

            @if ($this->serverCap !== null)
                <flux:text class="text-xs" data-test="media-server-cap">{{ __('site_settings.media.server_cap', ['size' => $this->serverCap]) }}</flux:text>
            @endif

            <flux:separator />

            <flux:heading level="2">{{ __('site_settings.media.storage.section') }}</flux:heading>

            <flux:radio.group wire:model.live="form.media_storage" :label="__('site_settings.media.storage.where')" :description="__('site_settings.media.storage.where_description')">
                <flux:radio value="public" :label="__('site_settings.media.storage.public')" :description="__('site_settings.media.storage.public_description')" />
                <flux:radio value="s3" :label="__('site_settings.media.storage.s3')" :description="__('site_settings.media.storage.s3_description')" />
            </flux:radio.group>
            <flux:error name="form.media_storage" />

            @if (MediaDisks::publishes((string) config('media.disk')))
                @php($directState = $this->directState)
                <div class="flex flex-wrap items-center gap-3" x-data="{ checking: false }" data-test="direct-status-{{ $directState }}">
                    <flux:text @class(['max-w-prose', 'text-amber-600 dark:text-amber-400' => in_array($directState, ['link_missing', 'unreachable'], true)])>{{ __('site_settings.media.storage.direct.'.$directState, ['url' => app(DirectServing::class)->probeUrl()]) }}</flux:text>

                    @if ($directState === 'link_missing')
                        <flux:button size="sm" wire:click="createStorageLink" data-test="create-storage-link">{{ __('site_settings.media.storage.direct.create_link') }}</flux:button>
                    @elseif ($directState !== 'direct')
                        <flux:button size="sm" x-bind:disabled="checking" x-on:click="checking = true; window.checkMediaDirect().then(() => $wire.$refresh()).finally(() => checking = false)" data-test="check-direct">{{ __('site_settings.media.storage.direct.check_again') }}</flux:button>
                    @endif
                </div>
            @endif

            @if ($this->filesOnOtherDisk > 0)
                <flux:callout icon="information-circle" variant="secondary" data-test="files-on-other-disk">
                    <flux:callout.text>
                        {{ trans_choice('site_settings.media.storage.other_disk', $this->filesOnOtherDisk, ['count' => $this->filesOnOtherDisk]) }}
                        @can('moveToDisk', Media::class)
                            <flux:link :href="route('admin.media.move')" wire:navigate data-test="move-files-link">{{ __('site_settings.media.storage.move_link', ['storage' => __('site_settings.media.storage.'.config('media.disk'))]) }}</flux:link>
                        @endcan
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($form->media_storage === 's3' || $this->hasS3Files)
            <div class="space-y-6" data-test="s3-fields">
                <div class="grid items-start gap-6 sm:grid-cols-2">
                    <flux:input wire:model="form.media_s3_endpoint" :label="__('site_settings.media.storage.endpoint')" :description:trailing="__('site_settings.media.storage.endpoint_description')" placeholder="https://s3.example.com" autocomplete="off" />
                    <flux:input wire:model="form.media_s3_region" :label="__('site_settings.media.storage.region')" placeholder="us-east-1" autocomplete="off" />
                </div>

                <div class="grid items-start gap-6 sm:grid-cols-2">
                    <flux:input wire:model="form.media_s3_bucket" :label="__('site_settings.media.storage.bucket')" autocomplete="off" />
                    <flux:input wire:model="form.media_s3_prefix" :label="__('site_settings.media.storage.prefix')" :description:trailing="__('site_settings.media.storage.prefix_description')" placeholder="prod" autocomplete="off" />
                </div>

                <div class="grid items-start gap-6 sm:grid-cols-2">
                    <flux:input wire:model="form.media_s3_key" :label="__('site_settings.media.storage.key')" autocomplete="off" />
                    <flux:input
                        wire:model="form.media_s3_secret"
                        type="password"
                        viewable
                        :label="__('site_settings.media.storage.secret')"
                        :description:trailing="$this->hasStoredS3Secret ? __('site_settings.media.storage.secret_keep') : ($this->hasEnvS3Secret ? __('site_settings.media.storage.secret_env') : null)"
                        autocomplete="new-password"
                    />
                </div>

                <flux:switch wire:model="form.media_s3_path_style" :label="__('site_settings.media.storage.path_style')" :description="__('site_settings.media.storage.path_style_description')" align="left" />

                <flux:input wire:model="form.media_s3_public_url" :label="__('site_settings.media.storage.public_url')" :description:trailing="__('site_settings.media.storage.public_url_description')" placeholder="https://cdn.example.com" autocomplete="off" />
            </div>
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

        @if ($tab === 'media' && $storageTest !== null && ($form->media_storage === 's3' || $this->hasS3Files))
            <flux:callout
                :icon="$storageTest['ok'] ? 'check-circle' : 'exclamation-triangle'"
                :variant="$storageTest['ok'] ? 'success' : 'danger'"
                data-test="storage-test-result"
            >
                <flux:callout.text>{{ $storageTest['message'] }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                @if ($tab === 'media' && ($form->media_storage === 's3' || $this->hasS3Files))
                    <flux:button variant="ghost" icon="signal" wire:click="testStorageConnection" data-test="test-storage-button">
                        {{ __('site_settings.media.storage.test') }}
                    </flux:button>
                @endif
                @if ($tab === 'mail')
                    <flux:button variant="ghost" icon="paper-airplane" wire:click="sendTestEmail" data-test="send-test-email-button">
                        {{ __('site_settings.mail.send_test') }}
                    </flux:button>
                @endif
            </div>

            @unless ($tab === 'logo')
                <flux:button variant="primary" type="submit" data-test="save-site-settings-button">
                    {{ __('common.actions.save') }}
                </flux:button>
            @endunless
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

    <x-confirm-modal
        wire:model.self="showS3ChangeModal"
        :heading="__('site_settings.media.storage.change_heading')"
        :confirm-label="__('site_settings.media.storage.change_confirm')"
        confirm-action="confirmS3Change"
    >
        {{ __('site_settings.media.storage.change_body') }}
    </x-confirm-modal>

    <flux:modal name="leave-site-settings" class="max-w-md md:min-w-md">
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('site_settings.unsaved.heading') }}</flux:heading>
                <flux:text>{{ __('site_settings.unsaved.leave_body') }}</flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>

                <flux:button variant="danger" x-on:click="leave()" data-test="leave-without-saving-button">
                    {{ __('site_settings.unsaved.leave') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
