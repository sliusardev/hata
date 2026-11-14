{{--
    A rich text field of a content type, in the same editor as the body. Receives $field, $model (the form property),
    $readOnly, $images (id => url of the images the form's texts hold) and $locale (the record's language).
--}}
<x-admin.rich-text wire:model="{{ $model }}" :label="$field->labelText()" :images="$images" :locale="$locale" :readonly="$readOnly" />
