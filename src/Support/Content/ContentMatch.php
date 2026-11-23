<?php

namespace Hata\Support\Content;

use Hata\Models\Entry;

/**
 * What a public address resolved to: a record to show (`preview` when it is not public and only an editor sees it),
 * the list of a type's public records (`listOf`), or the address it has moved to.
 */
final readonly class ContentMatch
{
    private function __construct(
        public ?Entry $entry,
        public bool $preview,
        public ?string $redirectTo,
        public ?ContentType $listOf = null,
    ) {}

    public static function entry(Entry $entry, bool $preview = false): self
    {
        return new self($entry, $preview, null);
    }

    public static function redirect(string $url): self
    {
        return new self(null, false, $url);
    }

    public static function list(ContentType $type): self
    {
        return new self(null, false, null, $type);
    }
}
