<?php

namespace Hata\Support\Content\Types;

use Hata\Support\Content\ContentType;

/**
 * A taxonomy of the posts: its records are terms, one per language like any record, and a post is filed under the
 * terms of its own language.
 */
class CategoriesType extends ContentType
{
    public function key(): string
    {
        return 'categories';
    }

    public function icon(): string
    {
        return 'folder';
    }

    public function hierarchical(): bool
    {
        return true;
    }

    public function base(string $locale): ?string
    {
        return 'categories';
    }

    public function taxonomyOf(): array
    {
        return ['posts'];
    }
}
