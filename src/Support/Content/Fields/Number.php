<?php

namespace App\Support\Content\Fields;

class Number extends Field
{
    protected function typeRules(): array
    {
        return ['numeric'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_numeric($formValue) ? $formValue + 0 : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_numeric($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'number';
    }
}
