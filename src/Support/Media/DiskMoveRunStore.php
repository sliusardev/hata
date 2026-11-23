<?php

namespace App\Support\Media;

/**
 * Keeps the "move files to another disk" run in the `media_move_run` settings row.
 *
 * @extends MediaBatchRunStore<DiskMoveRun>
 */
class DiskMoveRunStore extends MediaBatchRunStore
{
    public const string KEY = 'media_move_run';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function hasModeFields(array $value): bool
    {
        return is_string($value['target'] ?? null) && $value['target'] !== '';
    }

    protected function fromArray(array $value): DiskMoveRun
    {
        return DiskMoveRun::fromArray($value);
    }
}
