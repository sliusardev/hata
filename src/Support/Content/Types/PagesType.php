<?php

namespace Hata\Support\Content\Types;

use Hata\Support\Content\ContentType;

class PagesType extends ContentType
{
    /**
     * The type's key, for code that names the pages type itself (the home page is always one of its records).
     */
    public const string KEY = 'pages';

    public function translationPrefix(): string
    {
        return 'hata::';
    }

    public function key(): string
    {
        return self::KEY;
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
