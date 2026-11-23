<?php

namespace Hata\Support\Media;

use Carbon\CarbonImmutable;
use Hata\Enums\MoveOutcome;
use Hata\Models\Media;

/**
 * The saved progress of one "move files to another disk" run: the shared run fields plus the target disk and the
 * bytes moved.
 */
final class DiskMoveRun extends MediaBatchRun
{
    /**
     * @param  array<string, int>  $counts  Files per outcome value.
     * @param  list<array<string, mixed>>  $recent  Newest first: name, size, outcome.
     */
    public function __construct(
        string $id,
        string $status,
        public string $target,
        int $cursor,
        int $total,
        array $counts,
        CarbonImmutable $startedAt,
        public int $bytesMoved = 0,
        ?CarbonImmutable $heartbeatAt = null,
        ?string $driver = null,
        float $secondsSpent = 0.0,
        array $recent = [],
        ?int $inProgress = null,
    ) {
        parent::__construct($id, $status, $cursor, $total, $counts, $startedAt, $heartbeatAt, $driver, $secondsSpent, $recent, $inProgress);
    }

    /**
     * Files seen but left where they were (missing, failed, changed meanwhile).
     */
    public function skipped(): int
    {
        return $this->processed() - ($this->counts[MoveOutcome::Moved->value] ?? 0);
    }

    public function record(Media $media, MoveOutcome $outcome): void
    {
        $size = (int) $media->size;

        if ($outcome === MoveOutcome::Moved) {
            $this->bytesMoved += $size;
        }

        $this->count($media, $outcome->value, ['size' => $size]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return parent::toArray() + [
            'target' => $this->target,
            'bytes_moved' => $this->bytesMoved,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ...self::commonArguments($data),
            target: (string) $data['target'],
            bytesMoved: (int) ($data['bytes_moved'] ?? 0),
        );
    }
}
