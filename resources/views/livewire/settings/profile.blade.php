<?php

use Hata\Concerns\ProfileValidationRules;
use Hata\Exceptions\FormCardFailed;
use Hata\Livewire\Admin\Concerns\HasFormExtensions;
use Hata\Support\Admin\Forms\FormContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('hata::layouts.app')] #[Title('hata::settings.profile.title')] class extends Component {
    use HasFormExtensions;
    use ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->mountExtensions(Auth::user());
    }

    protected function formContext(): FormContext
    {
        return new FormContext('profile', null, Auth::user(), false);
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validateWithCards(fn () => $this->validate($this->profileRules($user->id)));

        if ($validated === null) {
            return;
        }

        try {
            DB::transaction(function () use ($user, $validated): void {
                $user->fill($validated);

                if ($user->isDirty('email')) {
                    $user->email_verified_at = null;
                }

                $user->save();

                FormCardFailed::guard(fn () => $this->saveExtensions($user));
            });
        } catch (FormCardFailed $failure) {
            // The transaction rolled the row back; the signed-in user in memory still holds the unsaved values.
            $user->refresh();

            $this->showCardFailure($failure);

            return;
        }

        Flux::toast(variant: 'success', text: __('hata::settings.profile.saved'));
    }
}; ?>

<section class="w-full">
    @include('hata::partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('hata::settings.profile.title') }}</flux:heading>

    <x-hata::settings.layout :heading="__('hata::settings.profile.heading')" :subheading="__('hata::settings.profile.description')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('hata::common.fields.name')" type="text" required autofocus autocomplete="name" />

            <flux:input wire:model="email" :label="__('hata::common.fields.email')" type="email" required autocomplete="email" />

            {{-- The registered form cards (site and module fields), bare like the fields above; one column, so the side cards come after the main ones. --}}
            @foreach (['main', 'side'] as $cardArea)
                @include('hata::partials.admin.forms.cards', ['cards' => $this->formCards, 'area' => $cardArea, 'frame' => '', 'values' => $this->extensions, 'livewire' => true, 'readOnly' => false, 'bare' => true])
            @endforeach

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full" data-test="update-profile-button">
                        {{ __('hata::common.actions.save') }}
                    </flux:button>
                </div>

            </div>
        </form>

        <livewire:hata::settings.delete-user-form />
    </x-hata::settings.layout>
</section>
