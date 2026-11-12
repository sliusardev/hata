<?php

namespace App\Support\Settings;

use App\Models\Media;
use App\Support\Media\MediaAcceptance;
use App\Support\Media\MediaDisks;
use ArrayObject;
use Illuminate\Support\Facades\Storage;

/**
 * The site logos: one for the light theme and one for the dark theme, each a file of the media library
 * (the setting holds its id). When only one is chosen, it is used for both themes.
 */
class SiteLogo
{
    /**
     * @var list<string>
     */
    public const array VARIANTS = ['light', 'dark'];

    /**
     * Extensions a logo may have.
     *
     * @var list<string>
     */
    public const array EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

    private const string MEMO_KEY = 'site-logo.memo';

    public static function isCustom(): bool
    {
        return self::media('light') !== null;
    }

    /**
     * The file for the variant, falling back to the other variant.
     */
    public static function media(string $variant): ?Media
    {
        $other = $variant === 'light' ? 'dark' : 'light';

        return self::own($variant) ?? self::own($other);
    }

    /**
     * The file chosen for exactly this variant, with no fallback; null when unset, deleted, missing on a local disk (a cloud disk is trusted, to save a request) or not a logo format.
     */
    public static function own(string $variant): ?Media
    {
        $id = config("admin.logo.{$variant}");

        if (! is_int($id)) {
            return null;
        }

        $memo = self::memo();

        if (! $memo->offsetExists($id)) {
            self::load();
        }

        return $memo[$id] ?? null;
    }

    /**
     * Look both variants up in one query and remember the usable ones for this request.
     */
    private static function load(): void
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn (string $variant): mixed => config("admin.logo.{$variant}"), self::VARIANTS),
            is_int(...),
        )));
        $found = Media::query()->whereKey($ids)->get()->keyBy('id');
        $memo = self::memo();

        foreach ($ids as $id) {
            $media = $found->get($id);

            $memo[$id] = $media !== null
                && (new MediaAcceptance('image', self::EXTENSIONS))->allows($media)
                && (MediaDisks::isCloud($media->disk) || Storage::disk($media->disk)->exists($media->path))
                ? $media
                : null;
        }
    }

    /**
     * The URL of the file for the variant; it carries the file's version, so a changed file shows up at once.
     */
    public static function url(string $variant): ?string
    {
        return self::media($variant)?->url();
    }

    /**
     * Forget the lookups of this request. Called whenever the settings are applied.
     */
    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO_KEY);
    }

    /**
     * @return ArrayObject<int, Media|null>
     */
    private static function memo(): ArrayObject
    {
        if (! app()->bound(self::MEMO_KEY)) {
            app()->instance(self::MEMO_KEY, new ArrayObject);
        }

        /** @var ArrayObject<int, Media|null> */
        return app(self::MEMO_KEY);
    }
}
