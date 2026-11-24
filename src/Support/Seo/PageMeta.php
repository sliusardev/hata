<?php

namespace Hata\Support\Seo;

use Hata\Models\Media;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Media\MediaAcceptance;

/**
 * What the head of one public page says about it (title, description, canonical address, share image, type,
 * noindex). Scoped to the request: controllers fill it, the head prints it, and each value falls back to the site's.
 */
class PageMeta
{
    public const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    private const int DESCRIPTION_LENGTH = 160;

    private ?string $title = null;

    private ?string $ogTitle = null;

    private ?string $description = null;

    private ?string $canonical = null;

    private ?Media $image = null;

    private string $type = 'website';

    private bool $noindex = false;

    private ?Media $siteImage = null;

    private bool $siteImageLoaded = false;

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function title(): string
    {
        return $this->title ?? (string) config('app.name');
    }

    public function setOgTitle(?string $title): static
    {
        $this->ogTitle = $title;

        return $this;
    }

    public function ogTitle(): string
    {
        return $this->ogTitle ?? $this->title();
    }

    public function setDescription(?string $text): static
    {
        $this->description = self::normalizeDescription($text);

        return $this;
    }

    public function description(): ?string
    {
        if ($this->description !== null) {
            return $this->description;
        }

        $site = config('hata.seo.description');
        $code = app(ContentLocales::class)->current()->code;

        return is_array($site) && is_string($site[$code] ?? null)
            ? self::normalizeDescription($site[$code])
            : null;
    }

    public function setCanonical(?string $url): static
    {
        $this->canonical = $url;

        return $this;
    }

    public function canonical(): ?string
    {
        return $this->noindex ? null : $this->canonical;
    }

    public function setImage(?Media $media): static
    {
        $this->image = $media;

        return $this;
    }

    public function image(): ?Media
    {
        if ($this->image !== null && self::isShareable($this->image)) {
            return $this->image;
        }

        if (! $this->siteImageLoaded) {
            $this->siteImageLoaded = true;
            $id = config('hata.seo.image');
            $media = is_numeric($id) ? Media::query()->find((int) $id) : null;
            $this->siteImage = $media !== null && self::isShareable($media) ? $media : null;
        }

        return $this->siteImage;
    }

    /**
     * @param  'website'|'article'  $type
     */
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function setNoindex(bool $noindex = true): static
    {
        $this->noindex = $noindex;

        return $this;
    }

    public function isNoindex(): bool
    {
        return $this->noindex;
    }

    /**
     * Plain text of at most 160 characters: tags stripped, entities decoded, whitespace collapsed, cut on a word
     * boundary with an ellipsis. Null when nothing is left.
     */
    public static function normalizeDescription(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));

        if ($plain === '') {
            return null;
        }

        if (mb_strlen($plain) <= self::DESCRIPTION_LENGTH) {
            return $plain;
        }

        $head = mb_substr($plain, 0, self::DESCRIPTION_LENGTH - 1);
        $space = mb_strrpos($head, ' ');

        if ($space !== false) {
            $head = mb_substr($head, 0, $space);
        }

        return rtrim($head).'…';
    }

    public static function isShareable(Media $media): bool
    {
        return (new MediaAcceptance('image', self::IMAGE_EXTENSIONS))->allows($media);
    }
}
