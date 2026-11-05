{{--
    Permission checkboxes grouped by resource, with select all / clear all per group.

    Expects: $permissionGroups (array<string, list<array{name: string, grantable: bool}>>), $readOnly (bool).
--}}
<div class="space-y-4">
    @foreach ($permissionGroups as $group => $permissions)
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700" wire:key="permission-group-{{ $group }}">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <flux:heading>{{ __("permissions.groups.{$group}") }}</flux:heading>

                @unless ($readOnly)
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" wire:click="selectGroup('{{ $group }}')" data-test="select-group-{{ $group }}">{{ __('roles.form.select_all') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="clearGroup('{{ $group }}')" data-test="clear-group-{{ $group }}">{{ __('roles.form.clear_all') }}</flux:button>
                    </div>
                @endunless
            </div>

            <flux:checkbox.group wire:model="form.permissions" class="grid gap-3 sm:grid-cols-2">
                @foreach ($permissions as $permission)
                    <flux:checkbox
                        :value="$permission['name']"
                        :label="__('permissions.items.'.$permission['name'])"
                        :description="$readOnly || $permission['grantable'] ? null : __('roles.form.permission_unavailable')"
                        :disabled="$readOnly || ! $permission['grantable']"
                        wire:key="permission-{{ $permission['name'] }}"
                    />
                @endforeach
            </flux:checkbox.group>
        </div>
    @endforeach

    <flux:error name="form.permissions" />
    <flux:error name="form.permissions.*" />
</div>
