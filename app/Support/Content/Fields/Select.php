<?php

namespace App\Support\Content\Fields;

use Illuminate\Validation\Rule;

class Select extends Field
{
    /**
     * @var array<string, string>
     */
    public array $options = [];

    /**
     * @param  array<string, string>  $options  value => translation key or label
     */
    public function options(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    protected function typeRules(): array
    {
        return [Rule::in(array_keys($this->options))];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return is_string($formValue) && $formValue !== '' ? $formValue : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_string($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'select';
    }
}
