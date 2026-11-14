<?php

namespace App\Support\Maintenance;

/**
 * Reads a log file in chunks from its end and marks each line up for the viewer.
 *
 * No request reads more than one chunk, whatever the file's size. The file is shown as it is: a line is
 * highlighted by what it looks like, nothing is parsed into entries.
 */
class LogReader
{
    /**
     * Bytes read per request.
     */
    public const int CHUNK = 262144;

    private const string ENTRY = '/^(\[\d{4}-\d{2}-\d{2}[T ][^\]]*\]) ([\w-]+\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):)(.*)$/s';

    private const string TRACE = '/^(#\d+ |\[stacktrace\]|\[previous exception\])/';

    /**
     * The chunk that ends at $before (the end of the file when null), as escaped HTML.
     *
     * It starts at the beginning of a line unless that line alone is longer than a chunk. `start` is where
     * the chunk begins in the file: pass it as $before to get the chunk above; 0 means the top is reached.
     *
     * @return array{html: string, start: int, end: int, size: int}
     */
    public function chunk(string $path, ?int $before = null): array
    {
        clearstatcache(true, $path);

        $size = (int) @filesize($path);
        $end = $before === null ? $size : max(0, min($before, $size));
        $start = max(0, $end - self::CHUNK);
        $text = $end > $start ? $this->read($path, max(0, $start - 1), $end) : '';

        if ($start > 0) {
            // One byte before the chunk was read too: a line break there means the chunk already starts at a line.
            $lineBreak = strpos($text, "\n");

            if ($lineBreak === false || $lineBreak === strlen($text) - 1) {
                $text = substr($text, 1);
            } else {
                $start += $lineBreak;
                $text = substr($text, $lineBreak + 1);
            }
        }

        return ['html' => $this->highlight($text), 'start' => $start, 'end' => $end, 'size' => $size];
    }

    private function read(string $path, int $from, int $to): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        try {
            fseek($handle, $from);
            $text = '';

            while (($left = $to - $from - strlen($text)) > 0 && ! feof($handle)) {
                $part = fread($handle, $left);

                if ($part === false || $part === '') {
                    break;
                }

                $text .= $part;
            }

            return $text;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Valid UTF-8, escaped, each line wrapped by what it is; line breaks stay as they are.
     */
    private function highlight(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $lines = explode("\n", mb_scrub($text, 'UTF-8'));

        return implode("\n", array_map($this->highlightLine(...), $lines));
    }

    private function highlightLine(string $line): string
    {
        if (preg_match(self::ENTRY, $line, $matches) === 1) {
            return '<span class="log-date">'.e($matches[1]).'</span> '
                .'<span class="log-level log-level-'.strtolower($matches[3]).'">'.e($matches[2]).'</span>'
                .e($matches[4]);
        }

        if (preg_match(self::TRACE, $line) === 1) {
            return '<span class="log-trace">'.e($line).'</span>';
        }

        return e($line);
    }
}
