{{--
    A choice of fixed options of a content type. Receives $field, $model (the form property) and $readOnly. Option
    labels may be translation keys.
--}}
<flux:select wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" class="max-w-xs">
    <flux:select.option value="">{{ __('content.form.select_none') }}</flux:select.option>
    @foreach ($field->options as $value => $label)
        <flux:select.option :value="$value" wire:key="{{ $model }}-{{ $value }}">{{ __($label) }}</flux:select.option>
    @endforeach
</flux:select>
