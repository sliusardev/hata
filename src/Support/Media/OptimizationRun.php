<?php

namespace Hata\Support\Media;

use Carbon\CarbonImmutable;
use Hata\Enums\OptimizeOutcome;
use Hata\Models\Media;

/**
 * The saved progress of one "optimize existing files" run: the shared run fields plus the settings fingerprint it
 * optimizes for and the bytes before/after of the rewritten files.
 */
final class OptimizationRun extends MediaBatchRun
{
    /**
     * @param  array<string, int>  $counts  Files per outcome value.
     * @param  list<array<string, mixed>>  $recent  Newest first: name, before, after, outcome.
     */
    public function __construct(
        string $id,
        string $status,
        public string $fingerprint,
        int $cursor,
        int $total,
        array $counts,
        public int $bytesBefore,
        public int $bytesAfter,
        CarbonImmutable $startedAt,
        ?CarbonImmutable $heartbeatAt = null,
        ?string $driver = null,
        float $secondsSpent = 0.0,
        array $recent = [],
        ?int $inProgress = null,
    ) {
        parent::__construct($id, $status, $cursor, $total, $counts, $startedAt, $heartbeatAt, $driver, $secondsSpent, $recent, $inProgress);
    }

    /**
     * Files seen but not rewritten (already optimal, too large, unreadable, missing, changed meanwhile).
     */
    public function skipped(): int
    {
        return $this->processed() - ($this->counts[OptimizeOutcome::Optimized->value] ?? 0);
    }

    /**
     * Bytes saved on the rewritten files; negative when they grew.
     */
    public function bytesSaved(): int
    {
        return $this->bytesBefore - $this->bytesAfter;
    }

    public function isStale(string $currentFingerprint): bool
    {
        return $this->fingerprint !== $currentFingerprint;
    }

    public function record(Media $media, OptimizeOutcome $outcome, int $before, int $after): void
    {
        if ($outcome === OptimizeOutcome::Optimized) {
            $this->bytesBefore += $before;
            $this->bytesAfter += $after;
        }

        $this->count($media, $outcome->value, ['before' => $before, 'after' => $after]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'fingerprint' => $this->fingerprint,
            'bytes_before' => $this->bytesBefore,
            'bytes_after' => $this->bytesAfter,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ...self::commonArguments($data),
            fingerprint: (string) $data['fingerprint'],
            bytesBefore: (int) ($data['bytes_before'] ?? 0),
            bytesAfter: (int) ($data['bytes_after'] ?? 0),
        );
    }
}
