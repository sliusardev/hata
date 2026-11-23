{{--
    Fields shared by the create and edit user pages.

    Expects: $mode ('create'|'edit'), $roleOptions (list of ['role' => Role, 'assignable' => bool]),
    $rolesLocked (bool), $editedUser (?User).
--}}
<div class="space-y-6">
    <flux:fieldset class="space-y-6">
        <flux:legend>{{ __('hata::users.form.account') }}</flux:legend>

        <flux:input wire:model="form.name" :label="__('hata::users.form.name')" type="text" required autocomplete="off" />

        <flux:input wire:model="form.email" :label="__('hata::users.form.email')" type="email" required autocomplete="off" />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:input
                wire:model="form.password"
                :label="__('hata::users.form.password')"
                type="password"
                :required="$mode === 'create'"
                autocomplete="new-password"
                :description:trailing="$mode === 'edit' ? __('hata::users.form.password_keep') : null"
                viewable
            />

            <flux:input
                wire:model="form.password_confirmation"
                :label="__('hata::users.form.password_confirmation')"
                type="password"
                :required="$mode === 'create'"
                autocomplete="new-password"
                viewable
            />
        </div>

        <flux:select wire:model="form.locale" :label="__('hata::users.form.locale')">
            @foreach (config('hata.admin.interface_locales') as $code => $languageName)
                <flux:select.option :value="$code" wire:key="locale-{{ $code }}">{{ $languageName }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($mode === 'create')
            <flux:checkbox wire:model="form.email_verified" :label="__('hata::users.form.email_verified')" :description="__('hata::users.form.email_verified_description')" />
        @else
            <flux:field>
                <flux:label>{{ __('hata::users.form.email_verified') }}</flux:label>
                <div>
                    @if ($editedUser?->email_verified_at)
                        <flux:badge color="green" icon="check-circle" size="sm">{{ __('hata::users.form.verified') }} · {{ $editedUser->email_verified_at->tz(config('hata.admin.timezone'))->isoFormat('LL') }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('hata::users.form.not_verified') }}</flux:badge>
                    @endif
                </div>
            </flux:field>
        @endif
    </flux:fieldset>

    <flux:separator variant="subtle" />

    <flux:fieldset>
        <flux:legend>{{ __('hata::users.form.roles') }}</flux:legend>
        <flux:description>{{ __('hata::users.form.roles_description') }}</flux:description>

        @if ($rolesLocked)
            <flux:callout class="mt-4" icon="lock-closed" variant="secondary" :heading="__('hata::users.form.own_roles_locked')" />
        @endif

        @if ($roleOptions === [])
            <flux:text class="mt-4">{{ __('hata::users.form.no_roles_available') }}</flux:text>
        @else
            <flux:checkbox.group wire:model="form.roles" class="mt-4 space-y-3">
                @foreach ($roleOptions as $option)
                    <flux:checkbox
                        :value="(string) $option['role']->id"
                        :label="$option['role']->displayName()"
                        :description="$option['assignable'] ? null : __('hata::users.form.role_unavailable')"
                        :disabled="$rolesLocked || ! $option['assignable']"
                        wire:key="role-option-{{ $option['role']->id }}"
                    />
                @endforeach
            </flux:checkbox.group>
        @endif

        <flux:error name="form.roles" />
        <flux:error name="form.roles.*" />
    </flux:fieldset>
</div>
