<?php

namespace App\Support\Content\Fields;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

class Date extends Field
{
    protected function typeRules(): array
    {
        return ['date_format:Y-m-d'];
    }

    /**
     * A malformed date gives null; validation reports it.
     */
    public function toStorage(mixed $formValue): mixed
    {
        if (! is_string($formValue) || $formValue === '') {
            return null;
        }

        try {
            return Carbon::parse($formValue)->format('Y-m-d');
        } catch (InvalidFormatException) {
            return null;
        }
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
