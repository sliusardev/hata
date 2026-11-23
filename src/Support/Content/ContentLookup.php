<?php

namespace Hata\Support\Content;

use Hata\Models\Entry;

/**
 * What a public address holds, before anything is known about the viewer (ContentRouter::lookup()): the record at
 * that path, whatever its status, or (`redirect`) the record an old path in `entry_redirects` points to, or the
 * list of a type's public records (`listOf`, the type's bare base, with no record). Whether the viewer may see a
 * record is decided later, by ContentRouter::resolve(); a list is public.
 */
final readonly class ContentLookup
{
    public function __construct(
        public ?Entry $entry,
        public bool $redirect,
        public ?ContentType $listOf = null,
    ) {}

    /**
     * Whether everyone may see what the address holds: a list, or a public record.
     */
    public function isPublic(): bool
    {
        return $this->listOf !== null || ($this->entry?->isPublic() ?? false);
    }
}
