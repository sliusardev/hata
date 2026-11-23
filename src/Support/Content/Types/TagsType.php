<?php

namespace Hata\Support\Content\Types;

use Hata\Support\Content\ContentType;

/**
 * A taxonomy of the posts: its records are terms, one per language like any record, and a post is filed under the
 * terms of its own language.
 */
class TagsType extends ContentType
{
    public function key(): string
    {
        return 'tags';
    }

    public function icon(): string
    {
        return 'tag';
    }

    public function hierarchical(): bool
    {
        return false;
    }

    public function base(string $locale): ?string
    {
        return 'tags';
    }

    public function taxonomyOf(): array
    {
        return ['posts'];
    }
}
