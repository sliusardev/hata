@props(['column', 'sortBy', 'direction', 'label' => null])

@if ($column->sort !== null)
    <flux:table.column sortable :sorted="$sortBy === $column->key" :direction="$direction" wire:click="sort('{{ $column->key }}')" :align="$column->align" :class="match ($column->hideBelow) { 'md' => 'max-md:hidden', 'lg' => 'max-lg:hidden', default => '' }">{{ $label ?? __($column->label) }}</flux:table.column>
@else
    <flux:table.column :align="$column->align" :class="match ($column->hideBelow) { 'md' => 'max-md:hidden', 'lg' => 'max-lg:hidden', default => '' }">{{ $label ?? __($column->label) }}</flux:table.column>
@endif
