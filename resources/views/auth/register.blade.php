<x-hata::layouts.auth :title="__('auth.register.title')">
    <div class="flex flex-col gap-6">
        <x-hata::auth-header :title="__('auth.register.heading')" :description="__('auth.register.description')" />

        <!-- Session Status -->
        <x-hata::auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('common.fields.name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('auth.register.name_placeholder')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('common.fields.email_address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('common.fields.password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('common.fields.password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('common.fields.confirm_password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('common.fields.confirm_password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('auth.register.submit') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('auth.register.have_account') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('auth.register.log_in') }}</flux:link>
        </div>
    </div>
</x-hata::layouts.auth>
