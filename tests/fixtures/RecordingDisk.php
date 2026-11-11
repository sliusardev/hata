<?php

namespace Tests\Fixtures;

use Closure;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * A faked disk that remembers the options each object was written with (the fake's local adapter drops them), and can
 * be told to fail its writes or deletes the way a cloud disk with `'throw' => false` does (returning false), or to run
 * a callback before each write.
 */
class RecordingDisk extends FilesystemAdapter
{
    /** @var array<string, array<string, mixed>> */
    public array $options = [];

    /** When true, put() and writeStream() write nothing and return false. */
    public bool $failWrites = false;

    /** When true, delete() deletes nothing and returns false. */
    public bool $failDeletes = false;

    /** @var (Closure(string): void)|null Runs before every put() and writeStream() with the path. */
    public ?Closure $beforeWrite = null;

    /** @var list<string> Paths read with readStream(), in order. */
    public array $streamed = [];

    public function readStream($path)
    {
        $this->streamed[] = $path;

        return parent::readStream($path);
    }

    public function put($path, $contents, $options = [])
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($path);
        }

        if ($this->failWrites) {
            return false;
        }

        $this->options[$path] = is_array($options) ? $options : ['visibility' => $options];

        return parent::put($path, $contents, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        if ($this->beforeWrite !== null) {
            ($this->beforeWrite)($path);
        }

        if ($this->failWrites) {
            return false;
        }

        $this->options[$path] = $options;

        return parent::writeStream($path, $resource, $options);
    }

    public function delete($paths)
    {
        if ($this->failDeletes) {
            return false;
        }

        return parent::delete($paths);
    }
}
