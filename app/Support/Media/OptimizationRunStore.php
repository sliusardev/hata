<?php

namespace App\Support\Media;

use App\Models\Setting;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Keeps the optimization run in its own settings row. It is read and written directly,
 * not through SiteSettings, so saving progress never flushes the site settings cache.
 */
class OptimizationRunStore
{
    public const string KEY = 'media_optimization_run';

    /**
     * @var list<string>
     */
    public const array STATUSES = ['running', 'paused', 'done'];

    /**
     * The stored run, or null when there is none or the stored value is malformed (a corrupt row counts as no run,
     * so the page and the command never fail on it). A run saved before runs had an `id` counts as malformed too:
     * it shows as no run and a new one can be started.
     */
    public function get(): ?OptimizationRun
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || ! $this->isWellFormed($value)) {
            return null;
        }

        try {
            return OptimizationRun::fromArray($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    public function save(OptimizationRun $run): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $run->toArray()]);
    }

    public function clear(): void
    {
        Setting::query()->where('key', self::KEY)->delete();
    }

    /**
     * Whether the keys OptimizationRun::fromArray() requires are present with usable values (dates are checked by parsing).
     *
     * @param  array<mixed>  $value
     */
    private function isWellFormed(array $value): bool
    {
        return is_string($value['id'] ?? null) && $value['id'] !== ''
            && in_array($value['status'] ?? null, self::STATUSES, true)
            && is_string($value['fingerprint'] ?? null)
            && is_int($value['cursor'] ?? null)
            && is_int($value['total'] ?? null)
            && is_string($value['started_at'] ?? null)
            && (($value['heartbeat_at'] ?? null) === null || is_string($value['heartbeat_at']));
    }
}
