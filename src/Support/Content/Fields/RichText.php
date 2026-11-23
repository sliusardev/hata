<?php

namespace Hata\Support\Content\Fields;

use Closure;
use Hata\Support\Content\BodySanitizer;
use Hata\Support\Content\EntryInput;

/**
 * Formatted text edited in the same editor as the body, and kept to the same rules: cleaned on save by
 * BodySanitizer (the security boundary, so it may be printed unescaped), at most EntryInput::BODY_MAX_BYTES as sent,
 * and its images recorded as media usages.
 */
class RichText extends Field
{
    protected function typeRules(): array
    {
        return ['string', function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strlen($value) > EntryInput::BODY_MAX_BYTES) {
                $fail('content.entry.body_too_long')->translate();
            }
        }];
    }

    /**
     * The cleaned HTML; null for an empty document.
     */
    public function toStorage(mixed $formValue): mixed
    {
        return is_string($formValue) ? app(BodySanitizer::class)->clean($formValue) : null;
    }

    public function toForm(mixed $stored): mixed
    {
        return is_string($stored) ? $stored : '';
    }

    public function view(): string
    {
        return 'rich-text';
    }

    public function mediaIds(mixed $stored): array
    {
        return is_string($stored) ? app(BodySanitizer::class)->mediaIds($stored) : [];
    }

    public function holdsMedia(): bool
    {
        return true;
    }

    public function withoutMedia(mixed $stored, int $mediaId): mixed
    {
        return is_string($stored) ? app(BodySanitizer::class)->withoutMedia($stored, $mediaId) : $stored;
    }
}
