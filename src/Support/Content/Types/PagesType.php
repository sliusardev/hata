<?php

namespace Hata\Support\Content\Types;

use Hata\Support\Content\ContentType;

class PagesType extends ContentType
{
    public function key(): string
    {
        return 'pages';
    }

    public function icon(): string
    {
        return 'document-text';
    }

    public function hierarchical(): bool
    {
        return true;
    }

    public function required(): bool
    {
        return true;
    }

    public function base(string $locale): ?string
    {
        return null;
    }
}
