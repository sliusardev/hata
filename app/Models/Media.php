<?php

namespace App\Models;

use App\Enums\MediaKind;
use App\Models\Concerns\HasSearchIndex;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
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
 * @property array<string, mixed>|null $edits
 * @property string|null $title
 * @property array<string, string>|null $alt
 * @property string|null $search_index
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'folder_id', 'uploaded_by', 'disk', 'path', 'original_path', 'thumb_path', 'name', 'kind', 'mime', 'size',
    'width', 'height', 'original_size', 'uploaded_extension', 'optimized_with', 'optimize_failure', 'edits', 'title', 'alt',
])]
#[Hidden(['search_index'])]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasSearchIndex;

    protected $table = 'media';

    protected static function booted(): void
    {
        static::creating(function (Media $media): void {
            if (blank($media->key)) {
                $media->key = (string) Str::ulid();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'alt' => 'array',
            'edits' => 'array',
            'kind' => MediaKind::class,
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

    public function url(): string
    {
        return route('media.show', [$this->key, $this->name]);
    }

    public function thumbUrl(): ?string
    {
        if ($this->thumb_path === null) {
            return null;
        }

        return route('media.show', [$this->key, $this->name, 'thumb' => 1]);
    }

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
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
        $default = (string) config('app.locale');

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
