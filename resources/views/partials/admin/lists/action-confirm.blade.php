{{-- The one confirmation dialog of a list's registered row actions. Needs the HasListExtensions trait. --}}
@if ($this->listRowActions !== [])
    <x-hata::confirm-modal
        wire:model.self="showRowActionModal"
        :heading="__($this->pendingRowAction?->label ?? '')"
        :confirm-label="__($this->pendingRowAction?->label ?? '')"
        confirm-action="runPendingRowAction"
        data-test="row-action-modal"
    >
        {{ __($this->pendingRowAction?->confirm ?? '') }}
    </x-hata::confirm-modal>
@endif
