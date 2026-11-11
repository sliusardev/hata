<?php

namespace App\Support\Media;

use App\Models\Setting;

/**
 * Keeps the optimization run in its own settings row. It is read and written directly,
 * not through SiteSettings, so saving progress never flushes the site settings cache.
 */
class OptimizationRunStore
{
    public const string KEY = 'media_optimization_run';

    public function get(): ?OptimizationRun
    {
        $value = Setting::query()->where('key', self::KEY)->value('value');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? OptimizationRun::fromArray($value) : null;
    }

    public function save(OptimizationRun $run): void
    {
        Setting::query()->updateOrCreate(['key' => self::KEY], ['value' => $run->toArray()]);
    }

    public function clear(): void
    {
        Setting::query()->where('key', self::KEY)->delete();
    }
}
