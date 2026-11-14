<?php

namespace Tests\Fixtures;

use App\Support\Content\ContentType;
use App\Support\Content\Fields\Date;
use App\Support\Content\Fields\Gallery;
use App\Support\Content\Fields\MediaFile;
use App\Support\Content\Fields\Number;
use App\Support\Content\Fields\RichText;
use App\Support\Content\Fields\Select;
use App\Support\Content\Fields\Text;
use App\Support\Content\Fields\Textarea;
use App\Support\Content\Fields\Toggle;

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
