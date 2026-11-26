@php
    $state = $record->state();
    $stateColor = match ($state) {
        Hata\Enums\EntryState::Published => 'green',
        Hata\Enums\EntryState::Scheduled => 'sky',
        default => 'zinc',
    };
@endphp
<flux:badge size="sm" :color="$stateColor" data-test="entry-state-{{ $record->id }}" data-state="{{ strtolower($state->name) }}">{{ __('hata::content.list.state.'.strtolower($state->name)) }}</flux:badge>
