<?php

namespace App\Support\Content\Fields;

class RichText extends Field
{
    protected function typeRules(): array
    {
        return ['string', 'max:200000'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_string($formValue) && trim($formValue) !== '' ? $formValue : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_string($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'rich-text';
    }
}
