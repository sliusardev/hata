{{-- The confirmation dialogs of a list's registered row and bulk actions. Needs the HasListExtensions trait. --}}
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
@if ($this->listBulkActions !== [])
    <x-hata::confirm-modal
        wire:model.self="showBulkActionModal"
        :heading="__($this->pendingBulkAction?->label ?? '')"
        :confirm-label="__($this->pendingBulkAction?->label ?? '')"
        confirm-action="runPendingBulkAction"
        data-test="bulk-action-modal"
    >
        {{ $this->pendingBulkAction?->confirm !== null ? __($this->pendingBulkAction->confirm) : trans_choice('hata::common.bulk.confirm', count($selected)) }}
    </x-hata::confirm-modal>
@endif
