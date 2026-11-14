<?php

namespace App\Rules;

use App\Models\Entry;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryPaths;
use App\Support\Localization\ReservedSlugs;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A slug typed for a record: the slug format, not reserved or a type's base when it sits at the top of the
 * address, not taken by another record of the type and language (trashed ones count), and with a resulting
 * path of at most 255 characters.
 */
class EntrySlug implements ValidationRule
{
    public const FORMAT = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

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

        if (preg_match(self::FORMAT, $slug) !== 1) {
            $fail('content.slug.format')->translate();

            return;
        }

        if ($this->isReserved($slug)) {
            $fail('content.slug.reserved')->translate();

            return;
        }

        $paths = app(EntryPaths::class);
        $path = $paths->pathFor($this->type, $this->parent, $slug);

        if (strlen($path) > self::MAX_PATH) {
            $fail('content.slug.too_long')->translate(['max' => self::MAX_PATH]);

            return;
        }

        if ($paths->scope($this->type, $this->locale, $this->ignore)->where('path', $path)->exists()) {
            $fail('content.slug.taken')->translate();
        }
    }

    /**
     * Only a slug at the top of the address can collide with a route, a language or the base of a type.
     */
    private function isReserved(string $slug): bool
    {
        if ($this->parent !== null || $this->type->base($this->locale) !== null) {
            return false;
        }

        if (app(ReservedSlugs::class)->contains($slug)) {
            return true;
        }

        foreach (app(ContentTypes::class)->all() as $type) {
            if ($type->base($this->locale) === $slug) {
                return true;
            }
        }

        return false;
    }
}
