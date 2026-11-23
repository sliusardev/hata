<?php

namespace App\Support\Media;

use App\Models\Media;
use Illuminate\Support\Str;

final class MediaNames
{
    /**
     * A slugged file name with the given extension that no other media row uses: "name.ext", "name-2.ext", ...
     */
    public static function unique(string $originalName, string $extension, ?int $ignoreId = null): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME), language: 'uk');
        $base = $base === '' ? 'file' : $base;

        $taken = fn (string $name): bool => Media::query()
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        $name = "{$base}.{$extension}";

        for ($suffix = 2; $taken($name); $suffix++) {
            $name = "{$base}-{$suffix}.{$extension}";
        }

        return $name;
    }
}
