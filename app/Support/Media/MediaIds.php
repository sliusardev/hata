<?php

namespace App\Support\Media;

use App\Models\Media;

/**
 * Media ids sent by the client (bulk selection, dragged tiles, the ZIP route).
 */
final class MediaIds
{
    /**
     * The most ids one request acts on.
     */
    public const int MAX = 400;

    /**
     * Unique positive integers (digit strings accepted), at most MAX of them, in the order given.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<int, int>
     */
    public static function normalize(array $ids): array
    {
        return array_slice(array_values(array_unique(array_map('intval', array_filter(
            $ids,
            fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)),
        )))), 0, self::MAX);
    }

    /**
     * The normalized ids that belong to existing files, in the order given.
     *
     * @param  array<array-key, mixed>  $ids
     * @return array<int, int>
     */
    public static function existing(array $ids): array
    {
        $ids = self::normalize($ids);

        if ($ids === []) {
            return [];
        }

        $existing = Media::query()->whereKey($ids)->pluck('id')->all();

        return array_values(array_intersect($ids, $existing));
    }
}
