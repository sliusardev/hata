<?php

namespace Hata\Support\Media;

use Hata\Enums\MediaKind;
use Hata\Models\Media;
use InvalidArgumentException;

/**
 * Which library files a field takes: a kind (`image`, `document` or `any`) and, optionally, a narrower list of
 * extensions. Shared by the media picker and the `MediaSelection` rule, so both decide the same way.
 */
final readonly class MediaAcceptance
{
    public const array ACCEPTS = ['image', 'document', 'any'];

    /**
     * @var list<string>|null
     */
    public ?array $extensions;

    /**
     * @param  array<array-key, string>|null  $extensions  Extensions of the stored name; null means any of the kind.
     */
    public function __construct(public string $accept = 'any', ?array $extensions = null)
    {
        if (! in_array($accept, self::ACCEPTS, true)) {
            throw new InvalidArgumentException("Unknown accept value [{$accept}].");
        }

        $this->extensions = $extensions === null
            ? null
            : array_values(array_unique(array_map(fn (string $extension): string => strtolower(ltrim($extension, '.')), $extensions)));
    }

    /**
     * The kind files must have, or null for any kind.
     */
    public function kind(): ?MediaKind
    {
        return $this->accept === 'any' ? null : MediaKind::from($this->accept);
    }

    /**
     * The `type` value for `MediaFilters`.
     */
    public function filterType(): string
    {
        return $this->kind()->value ?? 'all';
    }

    public function allowsKind(Media $media): bool
    {
        $kind = $this->kind();

        return $kind === null || $media->kind === $kind;
    }

    public function allowsExtension(Media $media): bool
    {
        return $this->extensions === null || in_array(strtolower($media->extension()), $this->extensions, true);
    }

    public function allows(Media $media): bool
    {
        return $this->allowsKind($media) && $this->allowsExtension($media);
    }

    /**
     * Extensions a stored upload may have: the given list, else every extension of the kind (null = any).
     *
     * @return list<string>|null
     */
    public function storedExtensions(): ?array
    {
        if ($this->extensions !== null) {
            return $this->extensions;
        }

        return $this->uploadExtensions();
    }

    /**
     * Extensions the browser may pick for upload: every library extension of the kind (null = any). The narrower
     * list isn't applied here, because the stored extension can differ (e.g. HEIC becomes WebP); the server checks it.
     *
     * @return list<string>|null
     */
    public function uploadExtensions(): ?array
    {
        $kind = $this->kind();

        if ($kind === null) {
            return null;
        }

        return array_keys(array_filter(MediaTypes::ALLOWED, fn (array $type): bool => $type['kind'] === $kind));
    }

    /**
     * The allowed ones of the given ids, existing, unique and in the order given.
     *
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    public function filterIds(array $ids): array
    {
        $ids = MediaIds::normalize($ids);

        if ($ids === []) {
            return [];
        }

        $allowed = Media::query()
            ->whereKey($ids)
            ->get()
            ->filter(fn (Media $media): bool => $this->allows($media))
            ->modelKeys();

        return array_values(array_intersect($ids, $allowed));
    }
}
