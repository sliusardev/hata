<x-hata::layouts.auth :title="__('hata::auth.confirm_password.title')">
    <div class="flex flex-col gap-6">
        <x-hata::auth-header
            :title="__('hata::auth.confirm_password.title')"
            :description="__('hata::auth.confirm_password.description')"
        />

        <x-hata::auth-session-status class="text-center" :status="session('status')" />

        <x-hata::passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('hata::auth.confirm_password.with_passkey')"
            :loading-label="__('hata::auth.confirm_password.confirming')"
            :separator="__('hata::auth.confirm_password.separator')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('hata::common.fields.password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('hata::common.fields.password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('hata::common.actions.confirm') }}
            </flux:button>
        </form>
    </div>
</x-hata::layouts.auth>
