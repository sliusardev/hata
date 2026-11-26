@props(['column', 'sortBy', 'direction'])

@if ($column->sort !== null)
    <flux:table.column sortable :sorted="$sortBy === $column->key" :direction="$direction" wire:click="sort('{{ $column->key }}')" :align="$column->align" :class="$column->hideClass()">{{ __($column->label) }}</flux:table.column>
@else
    <flux:table.column :align="$column->align" :class="$column->hideClass()">{{ __($column->label) }}</flux:table.column>
@endif
