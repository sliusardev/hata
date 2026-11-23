<?php

namespace Hata\Support\Media;

use Carbon\CarbonImmutable;
use Hata\Models\Media;

/**
 * The saved progress of a run that walks media rows in batches (optimizing existing files, moving files between
 * disks): what MediaBatchEngine, the queue jobs, the CLI commands and the pages share. Each mode adds its own fields.
 */
abstract class MediaBatchRun
{
    public const int RECENT_LIMIT = 10;

    /**
     * @param  string  $id  A ULID set when the run starts: tells this run apart from one started after it (even in the
     *                      same second) and is carried by its queue jobs.
     * @param  string  $status  running, paused or done.
     * @param  int  $cursor  The id of the last row processed; the run continues after it.
     * @param  array<string, int>  $counts  Files per outcome value.
     * @param  list<array<string, mixed>>  $recent  The newest files first: name, the mode's details, outcome.
     * @param  int|null  $inProgress  The media id a batch is processing right now, saved before the file is touched; if
     *                                the process dies on that file (e.g. a memory fatal), the next batch finds it here.
     */
    public function __construct(
        public string $id,
        public string $status,
        public int $cursor,
        public int $total,
        public array $counts,
        public CarbonImmutable $startedAt,
        public ?CarbonImmutable $heartbeatAt = null,
        public ?string $driver = null,
        public float $secondsSpent = 0.0,
        public array $recent = [],
        public ?int $inProgress = null,
    ) {}

    public function processed(): int
    {
        return array_sum($this->counts);
    }

    public function secondsLeft(int $remaining): ?int
    {
        $processed = $this->processed();

        if ($processed === 0) {
            return null;
        }

        return (int) round($this->secondsSpent / $processed * $remaining);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'cursor' => $this->cursor,
            'total' => $this->total,
            'counts' => $this->counts,
            'started_at' => $this->startedAt->toIso8601String(),
            'heartbeat_at' => $this->heartbeatAt?->toIso8601String(),
            'driver' => $this->driver,
            'seconds_spent' => $this->secondsSpent,
            'recent' => $this->recent,
            'in_progress' => $this->inProgress,
        ];
    }

    /**
     * Count one processed file: move the cursor past it and put it first in the recent list.
     *
     * @param  array<string, mixed>  $details  The mode's fields of the recent entry, between the name and the outcome.
     */
    protected function count(Media $media, string $outcome, array $details = []): void
    {
        $this->cursor = $media->id;
        $this->counts[$outcome] = ($this->counts[$outcome] ?? 0) + 1;

        array_unshift($this->recent, ['name' => $media->name] + $details + ['outcome' => $outcome]);
        $this->recent = array_slice($this->recent, 0, self::RECENT_LIMIT);
    }

    /**
     * The constructor arguments every run has, read from a stored array (named, to spread into a subclass constructor).
     *
     * @param  array<string, mixed>  $data
     * @return array{id: string, status: string, cursor: int, total: int, counts: array<string, int>, startedAt: CarbonImmutable, heartbeatAt: CarbonImmutable|null, driver: string|null, secondsSpent: float, recent: list<array<string, mixed>>, inProgress: int|null}
     */
    protected static function commonArguments(array $data): array
    {
        return [
            'id' => (string) $data['id'],
            'status' => (string) $data['status'],
            'cursor' => (int) $data['cursor'],
            'total' => (int) $data['total'],
            'counts' => array_map('intval', (array) ($data['counts'] ?? [])),
            'startedAt' => CarbonImmutable::parse($data['started_at']),
            'heartbeatAt' => filled($data['heartbeat_at'] ?? null) ? CarbonImmutable::parse($data['heartbeat_at']) : null,
            'driver' => $data['driver'] ?? null,
            'secondsSpent' => (float) ($data['seconds_spent'] ?? 0),
            'recent' => array_values((array) ($data['recent'] ?? [])),
            'inProgress' => is_int($data['in_progress'] ?? null) ? $data['in_progress'] : null,
        ];
    }
}
