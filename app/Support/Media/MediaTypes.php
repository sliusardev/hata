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
        'avif' => ['mimes' => ['image/avif', 'application/octet-stream'], 'kind' => MediaKind::Image],
        'heic' => ['mimes' => ['image/heic', 'image/heif', 'application/octet-stream'], 'kind' => MediaKind::Image],
        'heif' => ['mimes' => ['image/heif', 'image/heic', 'application/octet-stream'], 'kind' => MediaKind::Image],
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
     * Bytes read from the start of a file for the magic-byte checks; enough for an SVG root after a long
     * XML declaration, comment or DOCTYPE.
     */
    private const int HEAD_BYTES = 8192;

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

        if (! self::contentMatches($ext, $detected, self::readHead($file))) {
            return null;
        }

        return ['ext' => $ext, 'mime' => $type['mimes'][0], 'kind' => $type['kind']];
    }

    private static function readHead(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        $head = $path === false ? false : file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        return $head === false ? '' : $head;
    }

    /**
     * Magic-byte checks for types whose MIME may be reported generically, and for SVG.
     */
    private static function contentMatches(string $ext, string $detected, string $head): bool
    {
        $generic = in_array($detected, ['application/octet-stream', 'application/zip', 'application/vnd.ms-office'], true);

        return match ($ext) {
            'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp' => ! $generic || str_starts_with($head, "PK\x03\x04"),
            'doc', 'xls', 'ppt' => ! $generic || str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'mp3' => $detected !== 'application/octet-stream'
                || str_starts_with($head, 'ID3')
                || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0),
            'heic', 'heif' => $detected !== 'application/octet-stream'
                || self::hasBrand($head, ['heic', 'heix', 'hevc', 'heim', 'heis', 'mif1', 'msf1']),
            'avif' => $detected !== 'application/octet-stream' || self::hasBrand($head, ['avif', 'avis']),
            'svg' => preg_match('/^(?:\xEF\xBB\xBF)?\s*(?:<\?xml[^>]*\?>\s*|<!--.*?-->\s*|<!DOCTYPE[^>\[]*(?:\[.*?\])?\s*>\s*)*<svg[\s>]/is', $head) === 1,
            default => true,
        };
    }

    /**
     * @param  list<string>  $brands
     */
    private static function hasBrand(string $head, array $brands): bool
    {
        return substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), $brands, true);
    }

    /**
     * The extension for a MIME type: the first whitelist entry whose canonical MIME matches, else any entry listing it.
     */
    public static function extensionFor(string $mime): ?string
    {
        foreach ([true, false] as $canonicalOnly) {
            foreach (self::ALLOWED as $extension => $type) {
                if ($canonicalOnly ? $type['mimes'][0] === $mime : in_array($mime, $type['mimes'], true)) {
                    return $extension;
                }
            }
        }

        return null;
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
