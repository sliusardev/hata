<?php

namespace Hata\Support\Content\Fields;

class Toggle extends Field
{
    public function rules(): array
    {
        return ['boolean'];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return (bool) $formValue;
    }

    public function toForm(mixed $stored): mixed
    {
        return (bool) $stored;
    }

    public function view(): string
    {
        return 'toggle';
    }
}
