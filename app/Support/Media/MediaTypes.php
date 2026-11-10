<?php

namespace App\Support\Media;

use App\Enums\MediaKind;
use Illuminate\Http\UploadedFile;

/**
 * Whitelist of file types accepted by the media library.
 *
 * Each extension lists the MIME types libmagic may report for it; the first one is canonical.
 */
final class MediaTypes
{
    /**
     * @var array<string, array{mimes: list<string>, kind: MediaKind}>
     */
    public const array ALLOWED = [
        'jpg' => ['mimes' => ['image/jpeg'], 'kind' => MediaKind::Image],
        'jpeg' => ['mimes' => ['image/jpeg'], 'kind' => MediaKind::Image],
        'png' => ['mimes' => ['image/png'], 'kind' => MediaKind::Image],
        'webp' => ['mimes' => ['image/webp'], 'kind' => MediaKind::Image],
        'gif' => ['mimes' => ['image/gif'], 'kind' => MediaKind::Image],
        'svg' => ['mimes' => ['image/svg+xml', 'text/xml', 'application/xml'], 'kind' => MediaKind::Image],
        'avif' => ['mimes' => ['image/avif'], 'kind' => MediaKind::Image],
        'heic' => ['mimes' => ['image/heic', 'image/heif'], 'kind' => MediaKind::Image],
        'heif' => ['mimes' => ['image/heif', 'image/heic'], 'kind' => MediaKind::Image],
        'pdf' => ['mimes' => ['application/pdf'], 'kind' => MediaKind::Document],
        'docx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'xlsx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'pptx' => ['mimes' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'doc' => ['mimes' => ['application/msword', 'application/vnd.ms-office', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'xls' => ['mimes' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'ppt' => ['mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/octet-stream'], 'kind' => MediaKind::Document],
        'odt' => ['mimes' => ['application/vnd.oasis.opendocument.text', 'application/zip'], 'kind' => MediaKind::Document],
        'ods' => ['mimes' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'], 'kind' => MediaKind::Document],
        'odp' => ['mimes' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'], 'kind' => MediaKind::Document],
        'txt' => ['mimes' => ['text/plain'], 'kind' => MediaKind::Document],
        'csv' => ['mimes' => ['text/csv', 'text/plain', 'application/csv'], 'kind' => MediaKind::Document],
        'rtf' => ['mimes' => ['application/rtf', 'text/rtf', 'text/plain'], 'kind' => MediaKind::Document],
        'zip' => ['mimes' => ['application/zip', 'application/x-zip-compressed'], 'kind' => MediaKind::Other],
        'mp3' => ['mimes' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'], 'kind' => MediaKind::Other],
    ];

    /**
     * @return array{ext: string, mime: string, kind: MediaKind}|null
     */
    public static function detect(UploadedFile $file): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        $type = self::ALLOWED[$ext] ?? null;

        if ($type === null) {
            return null;
        }

        $detected = $file->getMimeType();

        if ($detected === null || ! in_array($detected, $type['mimes'], true)) {
            return null;
        }

        return ['ext' => $ext, 'mime' => $type['mimes'][0], 'kind' => $type['kind']];
    }

    public static function limitBytes(MediaKind $kind): int
    {
        $megabytes = $kind === MediaKind::Image
            ? config('media.limits.image_mb')
            : config('media.limits.document_mb');

        return (int) $megabytes * 1024 * 1024;
    }

    /**
     * @return 'inline'|'attachment'
     */
    public static function disposition(string $mime): string
    {
        return str_starts_with($mime, 'image/') || in_array($mime, ['application/pdf', 'audio/mpeg'], true)
            ? 'inline'
            : 'attachment';
    }

    public static function responseMime(string $mime): string
    {
        return in_array($mime, ['text/csv', 'text/plain'], true) ? 'text/plain; charset=UTF-8' : $mime;
    }
}
