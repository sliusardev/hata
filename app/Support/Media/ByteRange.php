<?php

namespace App\Support\Media;

/**
 * Single-range HTTP `Range` handling for streamed files.
 */
class ByteRange
{
    /**
     * The single byte range asked for: [start, end] inclusive, null to send the whole file (no or ignorable Range
     * header, multiple ranges, a stale If-Range) and false when it can't be satisfied.
     *
     * @return array{0: int, 1: int}|false|null
     */
    public static function parse(?string $header, ?string $ifRange, int $size, string $etag): array|false|null
    {
        if ($header === null || ! preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null;
        }

        if ($ifRange !== null && trim($ifRange, '"W/ ') !== $etag) {
            return null;
        }

        [, $from, $to] = $m;

        if ($from === '' && $to === '') {
            return null;
        }

        if ($from === '') {
            $length = (int) $to;

            return $length === 0 || $size === 0 ? false : [max(0, $size - $length), $size - 1];
        }

        $start = (int) $from;
        $end = $to === '' ? $size - 1 : min((int) $to, $size - 1);

        return $start >= $size || $end < $start ? false : [$start, $end];
    }

    /**
     * Echo bytes start..end (inclusive) of the stream, seeking when it can and reading past the start when it can't.
     *
     * @param  resource  $stream
     */
    public static function copy($stream, int $start, int $end): void
    {
        if ($start > 0 && @fseek($stream, $start) !== 0) {
            for ($skip = $start; $skip > 0; $skip -= strlen($chunk)) {
                $chunk = feof($stream) ? false : fread($stream, min(8192, $skip));

                if ($chunk === false || $chunk === '') {
                    return;
                }
            }
        }

        $left = $end - $start + 1;

        while ($left > 0 && ! feof($stream)) {
            $chunk = fread($stream, min(8192, $left));

            if ($chunk === false || $chunk === '') {
                break;
            }

            echo $chunk;
            $left -= strlen($chunk);
            flush();
        }
    }
}
