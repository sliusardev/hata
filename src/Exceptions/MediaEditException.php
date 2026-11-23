<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an image's edits (crop, rotate, flip, size) can't be applied. Nothing was written.
 *
 * The message is for logs; callers map the failure key to a translated field error.
 */
class MediaEditException extends RuntimeException
{
    /**
     * @param  'too_large'|'unreadable'|'not_editable'  $failure  The optimizer's failure key.
     */
    public function __construct(public readonly string $failure)
    {
        parent::__construct("The image edits could not be applied ({$failure}).");
    }

    /**
     * @param  'too_large'|'unreadable'|'not_editable'  $failure
     */
    public static function because(string $failure): self
    {
        return new self($failure);
    }
}
