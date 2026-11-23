<?php

namespace Hata\Support\Maintenance;

/**
 * The files the maintenance page may show and delete: the regular files directly in storage/logs.
 *
 * Every read and delete resolves its file through this class, so a name from the browser can only ever point
 * at a file this class lists.
 */
class LogFiles
{
    public function __construct(private readonly ?string $directory = null) {}

    /**
     * The files of the folder, newest first. Dot files, subfolders and symbolic links are left out.
     *
     * @return list<array{key: string, name: string, size: int, modified: int}>
     */
    public function all(): array
    {
        $directory = $this->directory();
        $names = is_dir($directory) ? scandir($directory) : false;

        if ($names === false) {
            return [];
        }

        $files = [];

        foreach ($names as $name) {
            $path = $directory.'/'.$name;

            if (str_starts_with($name, '.') || is_link($path) || ! is_file($path)) {
                continue;
            }

            $files[] = [
                'key' => self::key($name),
                'name' => $name,
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, fn (array $first, array $second): int => [$second['modified'], $first['name']] <=> [$first['modified'], $second['name']]);

        return $files;
    }

    /**
     * The listed file with this name.
     *
     * @return array{key: string, name: string, size: int, modified: int}|null
     */
    public function find(string $name): ?array
    {
        return array_find($this->all(), fn (array $file): bool => $file['name'] === $name);
    }

    /**
     * The listed file with this key (what the page passes to its actions instead of a raw file name).
     *
     * @return array{key: string, name: string, size: int, modified: int}|null
     */
    public function findByKey(string $key): ?array
    {
        return array_find($this->all(), fn (array $file): bool => $file['key'] === $key);
    }

    /**
     * The path of a listed file; null for anything else (a path, a dot file, a folder, a link, a missing file).
     */
    public function resolve(string $name): ?string
    {
        return $this->find($name) === null ? null : $this->directory().'/'.$name;
    }

    private function directory(): string
    {
        return rtrim($this->directory ?? storage_path('logs'), '/');
    }

    private static function key(string $name): string
    {
        return md5($name);
    }
}
