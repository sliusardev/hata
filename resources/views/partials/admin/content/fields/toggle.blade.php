{{-- A yes/no field of a content type. Receives $field, $model (the form property) and $readOnly. --}}
<flux:switch wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" align="left" />
