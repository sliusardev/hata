<?php

namespace Hata\Support\Install;

/**
 * The outcome of one `HostFiles` edit, with the lines to add by hand when the file was not recognized.
 */
final readonly class HostFileResult
{
    private function __construct(
        public HostFileStatus $status,
        public string $lines = '',
    ) {}

    public static function changed(): self
    {
        return new self(HostFileStatus::Changed);
    }

    public static function alreadyDone(): self
    {
        return new self(HostFileStatus::AlreadyDone);
    }

    public static function unrecognized(string $lines): self
    {
        return new self(HostFileStatus::Unrecognized, rtrim($lines));
    }
}
