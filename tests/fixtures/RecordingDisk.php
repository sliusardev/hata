<?php

namespace Tests\Fixtures;

use Illuminate\Filesystem\FilesystemAdapter;

/**
 * A faked disk that remembers the options each object was written with (the fake's local adapter drops them).
 */
class RecordingDisk extends FilesystemAdapter
{
    /** @var array<string, array<string, mixed>> */
    public array $options = [];

    public function put($path, $contents, $options = [])
    {
        $this->options[$path] = is_array($options) ? $options : ['visibility' => $options];

        return parent::put($path, $contents, $options);
    }

    public function writeStream($path, $resource, array $options = [])
    {
        $this->options[$path] = $options;

        return parent::writeStream($path, $resource, $options);
    }
}
