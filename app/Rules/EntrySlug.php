<?php

namespace App\Rules;

use App\Models\Entry;
use App\Support\Content\ContentType;
use App\Support\Content\EntryPaths;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A slug typed for a record: the slug format, not reserved or a type's base when it sits at the top of the
 * address, not taken by another record of the type and language (trashed ones count), and with a resulting
 * path of at most 255 characters.
 */
class EntrySlug implements ValidationRule
{
    /**
     * `\z`, not `$`, which would accept a trailing newline.
     */
    public const FORMAT = '/\A[a-z0-9]+(-[a-z0-9]+)*\z/';

    public const MAX_PATH = 255;

    public function __construct(
        private readonly ContentType $type,
        private readonly string $locale,
        private readonly ?Entry $parent = null,
        private readonly ?Entry $ignore = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = is_string($value) ? $value : '';

        $paths = app(EntryPaths::class);

        if (preg_match(self::FORMAT, $slug) !== 1) {
            $fail('content.slug.format')->translate();

            return;
        }

        if ($paths->isReserved($this->type, $this->locale, $this->parent, $slug)) {
            $fail('content.slug.reserved')->translate();

            return;
        }

        $path = $paths->pathFor($this->type, $this->parent, $slug);

        if (strlen($path) > self::MAX_PATH) {
            $fail('content.slug.too_long')->translate(['max' => self::MAX_PATH]);

            return;
        }

        // One query; a holder in the trash is named, so the user knows to restore it or delete it for good.
        $holder = $paths->scope($this->type, $this->locale, $this->ignore)->where('path', $path)->first(['id', 'deleted_at']);

        if ($holder !== null) {
            $fail($holder->trashed() ? 'content.slug.taken_trashed' : 'content.slug.taken')->translate();
        }
    }
}
