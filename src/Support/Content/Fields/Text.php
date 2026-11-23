<?php

namespace Hata\Support\Content\Fields;

class Text extends Field
{
    protected function typeRules(): array
    {
        return ['string', 'max:255'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_string($formValue) && trim($formValue) !== '' ? trim($formValue) : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_string($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'text';
    }
}
