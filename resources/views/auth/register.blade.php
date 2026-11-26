@php
    $registerContext = new Hata\Support\Admin\Forms\FormContext('register', null, null, true);
    $formCards = app(Hata\Support\Admin\Forms\FormCards::class)->for($registerContext);
    $formValues = [];
@endphp
<x-hata::layouts.auth :title="__('hata::auth.register.title')">
    <div class="flex flex-col gap-6">
        <x-hata::auth-header :title="__('hata::auth.register.heading')" :description="__('hata::auth.register.description')" />

        <!-- Session Status -->
        <x-hata::auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf
            <!-- Name -->
            <flux:input
                name="name"
                :label="__('hata::common.fields.name')"
                :value="old('name')"
                type="text"
                required
                autofocus
                autocomplete="name"
                :placeholder="__('hata::auth.register.name_placeholder')"
            />

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('hata::common.fields.email_address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            {{-- The registered form cards (site and module fields), plain fields in this form. --}}
            @include('hata::partials.admin.forms.cards', ['cards' => $formCards, 'area' => 'main', 'frame' => '', 'values' => $formValues, 'livewire' => false, 'readOnly' => false, 'bare' => true])

            <!-- Password -->
            <flux:input
                name="password"
                :label="__('hata::common.fields.password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('hata::common.fields.password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <!-- Confirm Password -->
            <flux:input
                name="password_confirmation"
                :label="__('hata::common.fields.confirm_password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('hata::common.fields.confirm_password')"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('hata::auth.register.submit') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('hata::auth.register.have_account') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('hata::auth.register.log_in') }}</flux:link>
        </div>
    </div>
</x-hata::layouts.auth>
