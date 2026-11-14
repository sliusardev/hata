<?php

namespace App\Models;

use App\Actions\Media\OptimizeImage;
use App\Enums\MediaKind;
use App\Models\Concerns\HasSearchIndex;
use App\Support\Localization\ContentLocales;
use App\Support\Media\DirectServing;
use App\Support\Media\MediaDisks;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $key
 * @property int|null $folder_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string|null $original_path
 * @property string|null $thumb_path
 * @property string $name
 * @property MediaKind $kind
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int|null $original_size
 * @property string|null $uploaded_extension
 * @property string|null $optimized_with
 * @property string|null $optimize_failure 'too_large' or 'unreadable' when an image was stored without optimizing
 * @property bool $animated Whether the image has several frames (GIF, WebP or APNG); such images can't be edited
 * @property array<string, mixed>|null $edits
 * @property int $version Bumped whenever the file's content changes; part of the public URL so caches refresh
 * @property string|null $title
 * @property array<string, string>|null $alt
 * @property string|null $search_index
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'folder_id', 'uploaded_by', 'disk', 'path', 'original_path', 'thumb_path', 'name', 'kind', 'mime', 'size',
    'width', 'height', 'original_size', 'uploaded_extension', 'optimized_with', 'optimize_failure', 'animated', 'edits', 'version', 'title', 'alt',
])]
#[Hidden(['search_index'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasSearchIndex;

    protected $table = 'media';

    private ?bool $editable = null;

    protected static function booted(): void
    {
        static::creating(function (Media $media): void {
            if (blank($media->key)) {
                $media->key = (string) Str::ulid();
            }
        });

        // A rebuild changes the file in place, so a remembered isEditable() answer would be stale.
        static::saved(function (Media $media): void {
            $media->editable = null;
        });
    }

    /**
     * Reload the attributes and forget the memoized isEditable() answer.
     */
    public function refresh(): static
    {
        $this->editable = null;

        return parent::refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'alt' => 'array',
            'animated' => 'bool',
            'edits' => 'array',
            'kind' => MediaKind::class,
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(MediaFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<MediaUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(MediaUsage::class);
    }

    /**
     * The URL to put in an <img> or link: the direct bucket/CDN URL for a raster image on a cloud disk that has a
     * public URL, otherwise the app route. Both carry `?v={version}`: a direct object path carries the version too,
     * but rows moved or imported may not, so the query string is what changes the URL for every rebuild.
     */
    public function url(): string
    {
        return $this->directUrl($this->path)
            ?? route('media.show', [$this->key, $this->name, 'v' => $this->version]);
    }

    public function thumbUrl(): ?string
    {
        if ($this->thumb_path === null) {
            return null;
        }

        return $this->directUrl($this->thumb_path, 'image/webp')
            ?? route('media.show', [$this->key, $this->name, 'thumb' => 1, 'v' => $this->version]);
    }

    /**
     * The stable `/media/{key}/{name}` URL, without a version: the one to copy into content, whichever disk the file is on.
     */
    public function appUrl(): string
    {
        return route('media.show', [$this->key, $this->name]);
    }

    /**
     * The public URL (with `?v=`) of a stored object (Flysystem's URL, which includes the disk's path prefix), or
     * null when the file must go through the app: local disks, disks without a public URL, and what is served as
     * SVG (always streamed, sandboxed) or as a document (signed, with its real name). `$mime` is the type being
     * served, which differs from the row's for a thumbnail (always WebP).
     */
    public function directUrl(string $path, ?string $mime = null): ?string
    {
        $mime ??= $this->mime;

        // The cheap checks first: a document or an SVG never asks DirectServing.
        if ($this->kind !== MediaKind::Image || $mime === 'image/svg+xml') {
            return null;
        }

        $cloud = MediaDisks::isCloud($this->disk) && MediaDisks::publicUrl($this->disk) !== null;

        if (! $cloud && ! app(DirectServing::class)->active($this->disk)) {
            return null;
        }

        return Storage::disk($this->disk)->url($path).'?v='.$this->version;
    }

    /**
     * The disk that keeps this file's original: the private disk when the file itself is served publicly.
     */
    public function originalsDisk(): string
    {
        return MediaDisks::originalsDisk($this->disk);
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /**
     * Still images that were not optimized with the given settings, oldest first.
     *
     * @param  Builder<Media>  $query
     */
    public function scopeOptimizationCandidates(Builder $query, string $fingerprint): void
    {
        $query->where('kind', MediaKind::Image)
            ->whereIn('mime', OptimizeImage::RASTER)
            ->where('animated', false)
            ->where(function (Builder $query) use ($fingerprint): void {
                $query->whereNull('optimized_with')->orWhere('optimized_with', '!=', $fingerprint);
            })
            ->orderBy('id');
    }

    /**
     * Whether the image can be cropped, rotated or flipped: a raster image that isn't animated. SVG, animated
     * GIF/WebP/APNG (the `animated` column), documents and files missing from the disk can't. The file is only checked for
     * existence, never read.
     */
    public function isEditable(): bool
    {
        if ($this->editable !== null) {
            return $this->editable;
        }

        if (! $this->isEditableKind()) {
            return $this->editable = false;
        }

        return $this->editable = Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Whether to offer editing in a menu: isEditable(), except that a cloud file is trusted to exist. The check
     * would be a request to the bucket on every render of the details panel; the crop page checks for real.
     */
    public function offersEditing(): bool
    {
        return MediaDisks::isCloud($this->disk) ? $this->isEditableKind() : $this->isEditable();
    }

    /**
     * A raster image that isn't animated, judged by the row alone.
     */
    private function isEditableKind(): bool
    {
        return $this->isImage() && $this->mime !== 'image/svg+xml' && ! $this->animated;
    }

    /**
     * The file extension in upper case, e.g. "PDF".
     */
    public function extension(): string
    {
        return Str::upper(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    /**
     * The file size in binary units with the current locale's decimal separator, e.g. "1,2 MB".
     */
    public function humanSize(): string
    {
        return self::formatBytes($this->size);
    }

    /**
     * A byte count in binary units with the current locale's decimal separator, e.g. "18,4 MB".
     */
    public static function formatBytes(int $bytes): string
    {
        return (string) Number::withLocale(app()->getLocale(), fn () => Number::fileSize($bytes, maxPrecision: 1));
    }

    /**
     * The extension the file was uploaded with (e.g. "JPG") when the stored file has another one, else null.
     */
    public function convertedFrom(): ?string
    {
        // Rows from before uploaded_extension existed fall back to the kept original's extension.
        $source = $this->uploaded_extension
            ?? ($this->original_path === null ? null : pathinfo($this->original_path, PATHINFO_EXTENSION));

        if ($source === null) {
            return null;
        }

        $original = Str::upper($source);
        $normalize = fn (string $extension): string => $extension === 'JPEG' ? 'JPG' : $extension;

        return $original !== '' && $normalize($original) !== $normalize($this->extension()) ? $original : null;
    }

    /**
     * The language whose alt text is always shown and used when a translation is empty:
     * the site's default language, or the first available one.
     */
    public static function primaryAltLocale(): string
    {
        $locales = (array) config('app.available_locales');
        $default = app(ContentLocales::class)->interfaceDefault();

        return array_key_exists($default, $locales) ? $default : (string) array_key_first($locales);
    }

    /**
     * Alt text in the given language, trimmed. An empty (or whitespace-only) translation falls back to the primary language
     * unless $fallback is false (the edit form needs the stored value).
     */
    public function altFor(string $locale, bool $fallback = true): string
    {
        $text = trim((string) ($this->alt[$locale] ?? ''));

        if ($text !== '' || ! $fallback) {
            return $text;
        }

        return trim((string) ($this->alt[self::primaryAltLocale()] ?? ''));
    }

    /**
     * Bytes saved by optimization, or null when the file did not shrink.
     */
    public function savedBytes(): ?int
    {
        if ($this->original_size === null || $this->original_size <= $this->size) {
            return null;
        }

        return $this->original_size - $this->size;
    }

    /**
     * The saving as a whole percentage of the uploaded size, or null when the file did not shrink.
     */
    public function savedPercent(): ?int
    {
        $saved = $this->savedBytes();

        return $saved === null ? null : (int) round($saved / (int) $this->original_size * 100);
    }

    protected function searchIndexSource(): string
    {
        return $this->name.' '.$this->title.' '.implode(' ', $this->alt ?? []);
    }
}
