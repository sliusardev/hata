{{-- A date field of a content type (no time). Receives $field, $model (the form property) and $readOnly. --}}
<flux:input type="date" wire:model="{{ $model }}" :label="$field->labelText()" :disabled="$readOnly" class="max-w-52" />
