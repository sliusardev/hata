<?php

namespace App\Rules;

use App\Support\Media\MediaAcceptance;
use App\Support\Media\MediaIds;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A media picker's value as the browser sent it: one id (or, with `multiple`, a list of ids) of existing files
 * whose kind matches `accept` and whose extension is in `extensions`. Forms check it on save, because the browser
 * can send any id.
 */
class MediaSelection implements ValidationRule
{
    private MediaAcceptance $acceptance;

    /**
     * @param  list<string>|null  $extensions
     */
    public function __construct(string $accept = 'any', private bool $multiple = false, ?array $extensions = null)
    {
        $this->acceptance = new MediaAcceptance($accept, $extensions);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ids = $this->ids($value);

        if ($ids === null || $this->acceptance->filterIds($ids) !== $ids) {
            $fail('media.picker.invalid')->translate();
        }
    }

    /**
     * The ids of a well-formed value, or null when its shape is wrong.
     *
     * @return list<int>|null
     */
    private function ids(mixed $value): ?array
    {
        $values = $this->multiple ? $value : [$value];

        if (! is_array($values) || ! array_is_list($values) || count($values) > MediaIds::MAX) {
            return null;
        }

        $ids = MediaIds::normalize($values);

        return count($ids) === count($values) ? $ids : null;
    }
}
