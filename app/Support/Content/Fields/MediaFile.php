<?php

namespace App\Support\Content\Fields;

use App\Rules\MediaSelection;

/**
 * One library file, of any kind unless narrowed with `accept()`.
 */
class MediaFile extends Field
{
    /**
     * The kind of file the field takes: `image`, `document` or `any`.
     */
    public string $accept = 'any';

    public function accept(string $accept): static
    {
        $this->accept = $accept;

        return $this;
    }

    protected function typeRules(): array
    {
        return [new MediaSelection($this->accept)];
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

    public function holdsMedia(): bool
    {
        return true;
    }

    public function withoutMedia(mixed $stored, int $mediaId): mixed
    {
        return $this->toStorage($stored) === $mediaId ? null : $stored;
    }
}
