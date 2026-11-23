<?php

namespace App\Support\Content\Types;

use App\Support\Content\ContentType;
use App\Support\Content\Fields\Field;
use App\Support\Content\Fields\Gallery;

class PostsType extends ContentType
{
    public function key(): string
    {
        return 'posts';
    }

    public function icon(): string
    {
        return 'newspaper';
    }

    public function hierarchical(): bool
    {
        return false;
    }

    /**
     * The first segment of the public address. Return another slug for a language to translate it
     * (`$locale === 'uk' ? 'blog' : 'news'`).
     */
    public function base(string $locale): ?string
    {
        return 'posts';
    }

    /**
     * The type's extra fields, from `App\Support\Content\Fields` (`Text::make('subtitle')`); `->shared()`
     * keeps one value for every language. Each label is `content.fields.<name>` in the language files.
     *
     * @return list<Field>
     */
    public function fields(): array
    {
        return [
            // Images of the post, shown under its text. One gallery per language, as the photos can differ.
            Gallery::make('gallery'),
        ];
    }
}
