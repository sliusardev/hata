{{-- The registered filters of a list (Admin::filters()), drawn like the list's own selects. Needs the HasListExtensions trait. --}}
@foreach ($this->listFilters as $filter)
    <flux:select wire:model.live="filters.{{ $filter->key }}" :aria-label="__($filter->label)" class="sm:max-w-52" wire:key="list-filter-{{ $filter->key }}" data-test="list-filter-{{ $filter->key }}">
        <flux:select.option value="">{{ __('hata::common.filters.all') }}</flux:select.option>
        @foreach ($this->listFilterOptions[$filter->key] as $value => $label)
            <flux:select.option :value="(string) $value" wire:key="list-filter-{{ $filter->key }}-{{ $value }}">{{ __($label) }}</flux:select.option>
        @endforeach
    </flux:select>
@endforeach
