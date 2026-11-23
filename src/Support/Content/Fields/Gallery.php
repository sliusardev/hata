<?php

namespace Hata\Support\Content\Fields;

use Hata\Rules\MediaSelection;

/**
 * Library images in the order they were picked.
 */
class Gallery extends Field
{
    /**
     * Each item must be an existing image.
     */
    protected function typeRules(): array
    {
        return ['array', 'max:100', new MediaSelection('image', multiple: true)];
    }

    /**
     * @return list<int>
     */
    public function toStorage(mixed $formValue): mixed
    {
        return $this->ids($formValue);
    }

    /**
     * @return list<int>
     */
    public function toForm(mixed $stored): mixed
    {
        return $this->ids($stored);
    }

    public function view(): string
    {
        return 'gallery';
    }

    public function mediaIds(mixed $stored): array
    {
        return $this->ids($stored);
    }

    public function holdsMedia(): bool
    {
        return true;
    }

    /**
     * @return list<int>
     */
    public function withoutMedia(mixed $stored, int $mediaId): mixed
    {
        return array_values(array_diff($this->ids($stored), [$mediaId]));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(
            intval(...),
            array_filter($value, fn (mixed $id): bool => is_numeric($id) && (int) $id > 0),
        )));
    }
}
