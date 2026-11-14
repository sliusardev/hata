<?php

namespace App\Support\Content;

use App\Models\Entry;

/**
 * What a public address resolved to: a record to show (`preview` when it is not public and only an editor sees it),
 * or the address it has moved to.
 */
final readonly class ContentMatch
{
    private function __construct(
        public ?Entry $entry,
        public bool $preview,
        public ?string $redirectTo,
    ) {}

    public static function entry(Entry $entry, bool $preview = false): self
    {
        return new self($entry, $preview, null);
    }

    public static function redirect(string $url): self
    {
        return new self(null, false, $url);
    }
}
