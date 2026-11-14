{{--
    One file from the media library. Receives $field, $model (the form property) and $readOnly. The picker shows the
    chosen file; a read-only form shows it without the buttons (see entry-form's read-only media list).
--}}
@if ($readOnly)
    @include('partials.admin.content.fields.read-only-media', ['label' => $field->labelText(), 'ids' => $field->mediaIds(data_get($this, $model))])
@else
    <livewire:media-picker wire:model="{{ $model }}" :accept="$field->accept" :label="$field->labelText()" :key="'field-picker-'.$field->name" />
    <flux:error :name="$model" />
@endif
