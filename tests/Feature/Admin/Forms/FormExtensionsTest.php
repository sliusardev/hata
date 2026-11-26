<?php

use Hata\Support\Admin\Forms\FormCard;
use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtension;
use Hata\Support\Admin\Forms\FormExtensions;
use Illuminate\Database\Eloquent\Model;

test('a wildcard rule is named after its parent field in messages', function () {
    $extension = new class extends FormExtension
    {
        public function fill(?Model $record, FormContext $context): array
        {
            return [];
        }

        public function rules(FormContext $context): array
        {
            return ['tags' => ['array'], 'tags.*' => ['string'], 'links.*.url' => ['url'], 'due_date' => ['date']];
        }

        public function view(): string
        {
            return '';
        }

        public function save(Model $record, array $values, FormContext $context): void {}
    };
    app()->instance($extension::class, $extension);

    $attributes = app(FormExtensions::class)->attributes([new FormCard('extra', $extension::class)], new FormContext('users', null, null, true));

    expect($attributes)->toBe([
        'extensions.extra.tags' => 'tags',
        'extensions.extra.tags.*' => 'tags',
        'extensions.extra.links.*.url' => 'url',
        'extensions.extra.due_date' => 'due date',
    ]);
});
