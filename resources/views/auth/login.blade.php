<x-hata::layouts.auth :title="__('hata::auth.login.title')">
    <div class="flex flex-col gap-6">
        <x-hata::auth-header :title="__('hata::auth.login.heading')" :description="__('hata::auth.login.description')" />

        <!-- Session Status -->
        <x-hata::auth-session-status class="text-center" :status="session('status')" />

        <x-hata::passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('hata::common.fields.email_address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('hata::common.fields.password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('hata::common.fields.password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('hata::auth.login.forgot_password') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('hata::auth.login.remember_me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('hata::auth.login.title') }}
                </flux:button>
            </div>
        </form>

        @if (Route::has('register') && config('hata.admin.registration_enabled'))
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('hata::auth.login.no_account') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('hata::auth.login.sign_up') }}</flux:link>
            </div>
        @endif
    </div>
</x-hata::layouts.auth>
