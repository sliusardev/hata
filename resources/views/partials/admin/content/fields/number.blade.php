{{-- A number field of a content type. Receives $field, $model (the form property) and $readOnly. --}}
<flux:input type="number" step="any" inputmode="decimal" wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" class="max-w-48" />
