<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A change to the site's content languages that was refused; the message is translated and shown to the user.
 */
class ContentLocaleException extends RuntimeException
{
    public static function unknown(): self
    {
        return new self(__('site_settings.languages.errors.unknown'));
    }

    public static function alreadyAdded(): self
    {
        return new self(__('site_settings.languages.errors.already_added'));
    }

    public static function notAdded(): self
    {
        return new self(__('site_settings.languages.errors.not_added'));
    }

    public static function isDefault(): self
    {
        return new self(__('site_settings.languages.errors.is_default'));
    }

    public static function inactive(): self
    {
        return new self(__('site_settings.languages.errors.inactive'));
    }
}
