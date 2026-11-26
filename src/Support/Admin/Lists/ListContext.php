<?php

namespace Hata\Support\Admin\Lists;

use Hata\Support\Content\ContentType;

/**
 * What a list extension is told about the list it is drawn for: its target (`users`, `content.posts`), the content
 * type (null for users) and the open tab (`all`, `trash`, …).
 */
final readonly class ListContext
{
    public function __construct(
        public string $target,
        public ?ContentType $type,
        public string $tab = 'all',
    ) {}

    public function isTrash(): bool
    {
        return $this->tab === 'trash';
    }

    public function isTaxonomy(): bool
    {
        return $this->type?->isTaxonomy() ?? false;
    }
}
