{{--
    Shared confirmation dialog for destructive actions in the admin panel.

    Usage:
    <x-confirm-modal wire:model.self="showDeleteModal" :heading="..." :confirm-label="..." confirm-action="deleteUser">
        Body text
    </x-confirm-modal>

    Optional: an `extra` slot for content below the text (lists, inputs), and `confirm-disabled`,
    an Alpine expression that disables the confirm button while it is true.
--}}
@props([
    'heading',
    'confirmLabel',
    'confirmAction',
    'confirmable' => true,
    'confirmDisabled' => null,
])

<flux:modal {{ $attributes->class('max-w-md md:min-w-md') }}>
    <div class="space-y-6">
        <div class="space-y-2">
            <flux:heading size="lg">{{ $heading }}</flux:heading>
            <flux:text>{{ $slot }}</flux:text>
        </div>

        @isset($extra)
            {{ $extra }}
        @endisset

        <div class="flex justify-end gap-3">
            <flux:modal.close>
                <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
            </flux:modal.close>

            @if ($confirmable)
                <flux:button variant="danger" wire:click="{{ $confirmAction }}" :x-bind:disabled="$confirmDisabled" data-test="confirm-modal-button">
                    {{ $confirmLabel }}
                </flux:button>
            @endif
        </div>
    </div>
</flux:modal>
