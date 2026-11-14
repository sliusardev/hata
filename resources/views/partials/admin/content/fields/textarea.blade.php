{{-- A multi-line text field of a content type. Receives $field, $model (the form property) and $readOnly. --}}
<flux:textarea wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" rows="3" resize="vertical" />
