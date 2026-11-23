<?php

namespace Hata\Enums;

enum MediaKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Other = 'other';

    /**
     * The Heroicon shown in place of a thumbnail.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Image => 'photo',
            self::Document => 'document-text',
            self::Other => 'paper-clip',
        };
    }
}
