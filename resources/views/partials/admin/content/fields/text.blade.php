{{-- A one-line text field of a content type. Receives $field, $model (the form property) and $readOnly. --}}
<flux:input wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" maxlength="255" />
