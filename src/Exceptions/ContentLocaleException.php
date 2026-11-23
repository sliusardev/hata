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

    /**
     * The language still has content records (those in the trash included); the message says how many.
     */
    public static function hasContent(int $count): self
    {
        return new self(trans_choice('site_settings.languages.errors.has_content', $count));
    }

    public static function inactive(): self
    {
        return new self(__('site_settings.languages.errors.inactive'));
    }
}
