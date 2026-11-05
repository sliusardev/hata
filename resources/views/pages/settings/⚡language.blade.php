<?php

use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('settings.language.title')] class extends Component {
    public string $locale = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->locale = Auth::user()->locale ?? app()->getLocale();
    }

    /**
     * Save the interface language for the currently authenticated user.
     */
    public function updateLocale(): void
    {
        $validated = $this->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('app.available_locales')))],
        ]);

        Auth::user()->update(['locale' => $validated['locale']]);

        app()->setLocale($validated['locale']);

        Toast::success(__('settings.language.saved'));

        $this->redirectRoute('language.edit');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('settings.language.title') }}</flux:heading>

    <x-pages::settings.layout :heading="__('settings.language.heading')" :subheading="__('settings.language.description')">
        <form wire:submit="updateLocale" class="my-6 w-full space-y-6">
            <flux:select wire:model="locale" :label="__('settings.language.label')">
                @foreach (config('app.available_locales') as $code => $name)
                    <flux:select.option :value="$code" wire:key="locale-{{ $code }}">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button variant="primary" type="submit" data-test="update-locale-button">
                {{ __('common.actions.save') }}
            </flux:button>
        </form>
    </x-pages::settings.layout>
</section>
