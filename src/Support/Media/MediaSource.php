<?php

namespace Hata\Support\Media;

use Hata\Models\Media;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The bytes a media file is rebuilt from: the kept original when its file exists, else the current file.
 */
final readonly class MediaSource
{
    /**
     * @param  string  $mime  The MIME of these bytes (for an original, sniffed from its content; the row's mime is the current file's).
     */
    public function __construct(
        public string $contents,
        public string $mime,
        public bool $isOriginal,
    ) {}

    /**
     * @throws RuntimeException when neither the original nor the current file can be read
     */
    public static function for(Media $media): self
    {
        $disk = Storage::disk($media->disk);
        $originals = Storage::disk($media->originalsDisk());
        $original = $media->original_path;

        if ($original !== null && $original !== '' && $originals->exists($original)) {
            $contents = (string) $originals->get($original);

            return new self($contents, MediaTypes::sniff($contents, $original) ?? $media->mime, isOriginal: true);
        }

        $contents = $disk->get($media->path);

        if ($contents === null) {
            throw new RuntimeException("The file of media {$media->id} is missing.");
        }

        return new self($contents, $media->mime, isOriginal: false);
    }

    /**
     * Width and height after applying the EXIF orientation, i.e. the frame the user sees.
     *
     * @return array{int, int}
     */
    public function orientedSize(): array
    {
        $image = Image::fromBytes($this->contents)->orient();

        return [$image->width(), $image->height()];
    }
}
