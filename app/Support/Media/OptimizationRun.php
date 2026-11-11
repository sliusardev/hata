<?php

namespace App\Support\Media;

use App\Enums\OptimizeOutcome;
use App\Models\Media;
use Carbon\CarbonImmutable;

/**
 * The saved progress of one "optimize existing files" run.
 */
final class OptimizationRun
{
    public const int RECENT_LIMIT = 10;

    /**
     * @param  array<string, int>  $counts  Files per outcome value.
     * @param  list<array{name: string, before: int, after: int, outcome: string}>  $recent  Newest first.
     * @param  int|null  $inProgress  The media id a batch is processing right now, saved before the file is touched; if
     *                                the process dies on that file (e.g. a memory fatal), the next batch finds it here.
     */
    public function __construct(
        public string $status,
        public string $fingerprint,
        public int $cursor,
        public int $total,
        public array $counts,
        public int $bytesBefore,
        public int $bytesAfter,
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

    public function isStale(string $currentFingerprint): bool
    {
        return $this->fingerprint !== $currentFingerprint;
    }

    public function record(Media $media, OptimizeOutcome $outcome, int $before, int $after): void
    {
        $this->cursor = $media->id;
        $this->counts[$outcome->value] = ($this->counts[$outcome->value] ?? 0) + 1;

        if ($outcome === OptimizeOutcome::Optimized) {
            $this->bytesBefore += $before;
            $this->bytesAfter += $after;
        }

        array_unshift($this->recent, [
            'name' => $media->name,
            'before' => $before,
            'after' => $after,
            'outcome' => $outcome->value,
        ]);
        $this->recent = array_slice($this->recent, 0, self::RECENT_LIMIT);
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
            'status' => $this->status,
            'fingerprint' => $this->fingerprint,
            'cursor' => $this->cursor,
            'total' => $this->total,
            'counts' => $this->counts,
            'bytes_before' => $this->bytesBefore,
            'bytes_after' => $this->bytesAfter,
            'started_at' => $this->startedAt->toIso8601String(),
            'heartbeat_at' => $this->heartbeatAt?->toIso8601String(),
            'driver' => $this->driver,
            'seconds_spent' => $this->secondsSpent,
            'recent' => $this->recent,
            'in_progress' => $this->inProgress,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: (string) $data['status'],
            fingerprint: (string) $data['fingerprint'],
            cursor: (int) $data['cursor'],
            total: (int) $data['total'],
            counts: array_map('intval', (array) ($data['counts'] ?? [])),
            bytesBefore: (int) ($data['bytes_before'] ?? 0),
            bytesAfter: (int) ($data['bytes_after'] ?? 0),
            startedAt: CarbonImmutable::parse($data['started_at']),
            heartbeatAt: filled($data['heartbeat_at'] ?? null) ? CarbonImmutable::parse($data['heartbeat_at']) : null,
            driver: $data['driver'] ?? null,
            secondsSpent: (float) ($data['seconds_spent'] ?? 0),
            recent: array_values((array) ($data['recent'] ?? [])),
            inProgress: is_int($data['in_progress'] ?? null) ? $data['in_progress'] : null,
        );
    }
}
