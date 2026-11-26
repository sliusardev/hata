<?php

namespace Hata\Support\Admin\Forms;

use Hata\Contracts\AdminUser;
use Hata\Support\Content\ContentType;

/**
 * What a form card is told about the form it is drawn in: its target (`content.posts`, `users`, `profile`,
 * `register`), the content type (null outside the entry form), the signed-in actor (null for a guest on
 * registration) and whether the form creates a record.
 */
final readonly class FormContext
{
    public function __construct(
        public string $target,
        public ?ContentType $type,
        public ?AdminUser $actor,
        public bool $creating,
    ) {}

    public function isCreate(): bool
    {
        return $this->creating;
    }
}
