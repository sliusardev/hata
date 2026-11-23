<?php

namespace App\Support\Media;

/**
 * Keeps the "optimize existing files" run in the `media_optimization_run` settings row.
 *
 * @extends MediaBatchRunStore<OptimizationRun>
 */
class OptimizationRunStore extends MediaBatchRunStore
{
    public const string KEY = 'media_optimization_run';

    protected function key(): string
    {
        return self::KEY;
    }

    protected function hasModeFields(array $value): bool
    {
        return is_string($value['fingerprint'] ?? null);
    }

    protected function fromArray(array $value): OptimizationRun
    {
        return OptimizationRun::fromArray($value);
    }
}
