<?php

namespace Hata\Support\Media;

use Hata\Enums\MediaKind;
use Hata\Exceptions\MediaUploadException;
use Illuminate\Http\UploadedFile;

/**
 * The checks every incoming media file goes through (upload and replace): supported type, size limit.
 */
final class MediaUploadChecks
{
    /**
     * @param  int|null  $pickedSize  Size of the file the user picked, when the browser shrank it before upload.
     * @return array{ext: string, mime: string, kind: MediaKind, size: int} The detected type and the size that was checked
     *                                                                      (the larger of the picked and the received size).
     *
     * @throws MediaUploadException
     */
    public static function validate(UploadedFile $file, ?int $pickedSize = null): array
    {
        $type = MediaTypes::detect($file);

        if ($type === null) {
            throw MediaUploadException::unsupported($file->getClientOriginalName());
        }

        $checkedSize = max($pickedSize ?? 0, (int) $file->getSize());

        if ($checkedSize > MediaTypes::limitBytes($type['kind'])) {
            throw MediaUploadException::tooLarge($checkedSize, MediaTypes::limitBytes($type['kind']));
        }

        return [...$type, 'size' => $checkedSize];
    }

    /**
     * The client-reported extension, lowercased, when it is one the library accepts.
     */
    public static function pickedExtension(?string $extension): ?string
    {
        $extension = strtolower(trim((string) $extension));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return array_key_exists($extension, MediaTypes::ALLOWED) ? $extension : null;
    }
}
