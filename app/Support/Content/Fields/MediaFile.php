<?php

namespace App\Support\Content\Fields;

class MediaFile extends Field
{
    protected function typeRules(): array
    {
        return ['integer', 'exists:media,id'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_numeric($formValue) && (int) $formValue > 0 ? (int) $formValue : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_numeric($stored) ? (int) $stored : null;
    }

    public function view(): string
    {
        return 'media-file';
    }

    public function mediaIds(mixed $stored): array
    {
        $id = $this->toStorage($stored);

        return $id === null ? [] : [$id];
    }
}
