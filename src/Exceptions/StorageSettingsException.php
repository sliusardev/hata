<?php

namespace App\Exceptions;

/**
 * A storage setting that cannot be saved. `$field` is the form field (without the form prefix) the message belongs to.
 */
class StorageSettingsException extends ProtectedActionException
{
    public string $field = 'media_storage';

    public static function on(string $field, string $message): self
    {
        $exception = new self($message);
        $exception->field = $field;

        return $exception;
    }
}
