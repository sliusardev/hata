<?php

namespace Tests\Fixtures;

use Hata\Support\Content\ContentType;
use Hata\Support\Content\Fields\Date;
use Hata\Support\Content\Fields\Gallery;
use Hata\Support\Content\Fields\MediaFile;
use Hata\Support\Content\Fields\Number;
use Hata\Support\Content\Fields\RichText;
use Hata\Support\Content\Fields\Select;
use Hata\Support\Content\Fields\Text;
use Hata\Support\Content\Fields\Textarea;
use Hata\Support\Content\Fields\Toggle;

/**
 * Test-only type with one field of every kind; `price` is shared between languages.
 */
class ProductsType extends ContentType
{
    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Product';
    }

    public function pluralLabel(): string
    {
        return 'Products';
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

    public function fields(): array
    {
        return [
            Text::make('subtitle'),
            Textarea::make('summary'),
            RichText::make('details'),
            Number::make('price')->shared()->required(),
            Date::make('released'),
            Toggle::make('featured'),
            Select::make('size')->options(['s' => 'Small', 'l' => 'Large']),
            MediaFile::make('cover'),
            Gallery::make('photos'),
        ];
    }
}
