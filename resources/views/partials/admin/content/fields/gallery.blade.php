{{-- Several images from the media library, in the order they were picked. Receives $field, $model and $readOnly. --}}
@if ($readOnly)
    @include('hata::partials.admin.content.fields.read-only-media', ['label' => $field->labelText(), 'ids' => $field->mediaIds(data_get($this, $model))])
@else
    <livewire:hata::media-picker wire:model="{{ $model }}" accept="image" multiple :label="$field->labelText()" :key="'field-picker-'.$field->name" />
    <flux:error :name="$model" />
@endif
