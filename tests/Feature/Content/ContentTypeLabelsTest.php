<?php

use Hata\Support\Content\ContentType;
use Hata\Support\Content\Types\PagesType;

it('reads a site type\'s labels from the site\'s translations', function () {
    $type = new class extends ContentType
    {
        public function key(): string
        {
            return 'products';
        }

        public function icon(): string
        {
            return 'shopping-bag';
        }

        public function hierarchical(): bool
        {
            return false;
        }

        public function base(string $locale): ?string
        {
            return 'products';
        }
    };

    trans()->addLines(['content.types.products.label' => 'Products'], 'en');

    expect($type->translationPrefix())->toBe('')
        ->and($type->label())->toBe('Products');
});

it('reads kit type labels from hata::', function () {
    $type = new PagesType;

    expect($type->translationPrefix())->toBe('hata::')
        ->and($type->label())->toBe(__('hata::content.types.pages.label'))
        ->and($type->label())->not->toContain('content.types');
});
