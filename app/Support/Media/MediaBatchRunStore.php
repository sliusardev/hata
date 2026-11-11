<?php

namespace App\Support\Media;

use App\Models\Setting;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Keeps one batch run in its own settings row (one row per mode). It is read and written directly, not through
 * SiteSettings, so saving progress never flushes the site settings cache.
 *
 * @template TRun of MediaBatchRun
 */
abstract class MediaBatchRunStore
{
    /**
     * @var list<string>
     */
    public const array STATUSES = ['running', 'paused', 'done'];

    /**
     * The settings key of the row.
     */
    abstract protected function key(): string;

    /**
     * Whether the mode's own fields are present with usable values.
     *
     * @param  array<mixed>  $value
     */
    abstract protected function hasModeFields(array $value): bool;

    /**
     * @param  array<string, mixed>  $value  A well-formed stored value.
     * @return TRun
     */
    abstract protected function fromArray(array $value): MediaBatchRun;

    /**
     * The stored run, or null when there is none or the stored value is malformed (a corrupt row counts as no run,
     * so the page and the command never fail on it). A run saved before runs had an `id` counts as malformed too:
     * it shows as no run and a new one can be started.
     *
     * @return TRun|null
     */
    public function get(): ?MediaBatchRun
    {
        $value = Setting::query()->where('key', $this->key())->value('value');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value) || ! $this->isWellFormed($value)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $value */
            return $this->fromArray($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @param  TRun  $run
     */
    public function save(MediaBatchRun $run): void
    {
        Setting::query()->updateOrCreate(['key' => $this->key()], ['value' => $run->toArray()]);
    }

    public function clear(): void
    {
        Setting::query()->where('key', $this->key())->delete();
    }

    /**
     * Whether the keys fromArray() requires are present with usable values (dates are checked by parsing).
     *
     * @param  array<mixed>  $value
     */
    private function isWellFormed(array $value): bool
    {
        return is_string($value['id'] ?? null) && $value['id'] !== ''
            && in_array($value['status'] ?? null, self::STATUSES, true)
            && is_int($value['cursor'] ?? null)
            && is_int($value['total'] ?? null)
            && is_string($value['started_at'] ?? null)
            && (($value['heartbeat_at'] ?? null) === null || is_string($value['heartbeat_at']))
            && $this->hasModeFields($value);
    }
}
