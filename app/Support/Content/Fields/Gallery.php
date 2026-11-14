<?php

namespace App\Support\Content\Fields;

class Gallery extends Field
{
    protected function typeRules(): array
    {
        return ['array', 'max:100'];
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
