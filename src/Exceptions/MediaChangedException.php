<?php

namespace Hata\Exceptions;

use RuntimeException;

/**
 * Thrown by RebuildMediaFile when the row it was asked to rewrite changed (another rebuild stored a new version) or was
 * deleted after the caller loaded it. Nothing was written: the files are rolled back, or, for a deleted row, removed.
 */
class MediaChangedException extends RuntimeException
{
    public function __construct(public readonly bool $gone)
    {
        parent::__construct($gone ? 'The media item was deleted meanwhile.' : 'The media item was changed meanwhile.');
    }

    public static function changed(): self
    {
        return new self(false);
    }

    public static function gone(): self
    {
        return new self(true);
    }

    /**
     * The translated message the admin UI shows for it.
     */
    public function userMessage(): string
    {
        return $this->gone ? __('hata::media.errors.deleted_meanwhile') : __('hata::media.errors.changed_meanwhile');
    }
}
