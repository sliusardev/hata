<?php

namespace App\Support\Content\Fields;

use Illuminate\Support\Carbon;

class Date extends Field
{
    protected function typeRules(): array
    {
        return ['date_format:Y-m-d'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_string($formValue) && $formValue !== '' ? Carbon::parse($formValue)->format('Y-m-d') : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_string($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'date';
    }
}
