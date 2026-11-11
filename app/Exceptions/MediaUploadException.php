<?php

namespace App\Exceptions;

use App\Enums\MediaKind;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Number;
use Throwable;

/**
 * Thrown when an uploaded file is rejected. The message is translated and safe to show to the user.
 */
class MediaUploadException extends AuthorizationException
{
    public static function unsupported(string $name): self
    {
        return new self(__('media.errors.unsupported', ['name' => $name]));
    }

    public static function tooLarge(int $sizeBytes, int $limitBytes): self
    {
        return new self(__('media.errors.too_large', [
            'size' => self::megabytes($sizeBytes),
            'limit' => self::megabytes($limitBytes),
        ]));
    }

    public static function heicUnsupported(): self
    {
        return new self(__('media.errors.heic_unsupported'));
    }

    public static function otherKind(MediaKind $current): self
    {
        return new self(__('media.errors.other_kind', ['kind' => __("media.errors.kinds.{$current->value}")]));
    }

    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self(__('media.errors.storage_failed'), previous: $previous);
    }

    private static function megabytes(int $bytes): string
    {
        return (string) Number::format($bytes / 1024 / 1024, precision: 1);
    }
}
