<?php

namespace App\Support\Content;

use App\Models\Entry;

/**
 * What a public address holds, before anything is known about the viewer (ContentRouter::lookup()): the record at
 * that path, whatever its status, or (`redirect`) the record an old path in `entry_redirects` points to. Whether the
 * viewer may see it is decided later, by ContentRouter::resolve().
 */
final readonly class ContentLookup
{
    public function __construct(
        public Entry $entry,
        public bool $redirect,
    ) {}
}
