<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Thrown by actions when a protection rule forbids an operation (e.g. deleting the last
 * super admin). The message is translated and safe to show to the user.
 */
class ProtectedActionException extends AuthorizationException
{
    /**
     * @param  array<string, string|int>  $replace
     */
    public static function because(string $translationKey, array $replace = []): self
    {
        return new self(__($translationKey, $replace));
    }
}
