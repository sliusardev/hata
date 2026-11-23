<?php

use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Attributes\Title;

new #[Layout('hata::layouts.app')] #[Title('hata::settings.appearance.title')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('hata::partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('hata::settings.appearance.title') }}</flux:heading>

    <x-hata::settings.layout :heading="__('hata::settings.appearance.heading')" :subheading="__('hata::settings.appearance.description')">
        <flux:radio.group x-data variant="segmented" x-model="$flux.appearance">
            <flux:radio value="light" icon="sun">{{ __('hata::settings.appearance.light') }}</flux:radio>
            <flux:radio value="dark" icon="moon">{{ __('hata::settings.appearance.dark') }}</flux:radio>
            <flux:radio value="system" icon="computer-desktop">{{ __('hata::settings.appearance.system') }}</flux:radio>
        </flux:radio.group>
    </x-hata::settings.layout>
</section>
