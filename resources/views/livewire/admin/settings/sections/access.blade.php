<?php

use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Enums\SystemRole;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Models\Role;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

new class extends SettingsSectionPage {
    public static function key(): string
    {
        return 'access';
    }

    protected function fields(): array
    {
        return ['registration_enabled', 'default_role'];
    }

    protected function rules(): array
    {
        return [
            'values.registration_enabled' => ['boolean'],
            'values.default_role' => ['nullable', 'string', Rule::exists('roles', 'name'), Rule::notIn([SystemRole::SuperAdmin->value])],
        ];
    }

    protected function prepare(array $validated): array
    {
        if (array_key_exists('registration_enabled', $validated)) {
            $validated['registration_enabled'] = (bool) $validated['registration_enabled'];
        }

        return parent::prepare($validated);
    }

    protected function persist(array $changed): void
    {
        try {
            app(UpdateSiteSettings::class)->handle(Auth::user(), $changed);
        } catch (ProtectedActionException $exception) {
            throw ValidationException::withMessages(['values.default_role' => $exception->getMessage()]);
        }
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
        $option = $this->roleOptions->first(fn (array $option): bool => $option['role']->name === ($this->values['default_role'] ?? ''));

        return $option !== null && $option['role']->hasPermissionTo('admin.access');
    }
}; ?>

<x-hata::admin.settings-section>
    <flux:switch wire:model="values.registration_enabled" :label="__('hata::site_settings.access.registration')" :description="__('hata::site_settings.access.registration_description')" align="left" />

    <flux:select wire:model.live="values.default_role" :label="__('hata::site_settings.access.default_role')" :description="__('hata::site_settings.access.default_role_description')">
        <flux:select.option value="">{{ __('hata::site_settings.access.no_role') }}</flux:select.option>
        @foreach ($this->roleOptions as $option)
            <flux:select.option :value="$option['role']->name" :disabled="! $option['assignable']" wire:key="role-{{ $option['role']->id }}">
                {{ $option['role']->displayName() }}{{ $option['assignable'] ? '' : ' — '.__('hata::site_settings.access.role_unavailable') }}
            </flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->defaultRoleGrantsAdminAccess)
        <flux:callout icon="exclamation-triangle" color="amber" :heading="__('hata::site_settings.access.admin_access_warning')" />
    @endif
</x-hata::admin.settings-section>
