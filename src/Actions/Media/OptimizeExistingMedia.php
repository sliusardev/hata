<?php

namespace Hata\Actions\Media;

use App\Models\User;
use Hata\Enums\OptimizeOutcome;
use Hata\Exceptions\MediaChangedException;
use Hata\Exceptions\OptimizationDisabledException;
use Hata\Models\Media;
use Hata\Support\Media\ImageEdits;
use Hata\Support\Media\MediaBatchRun;
use Hata\Support\Media\MediaSource;
use Hata\Support\Media\OptimizationRun;
use Hata\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The engine of "Optimize existing files": walks the candidates of the current run by ascending id and rebuilds each
 * one with the current settings, in batches limited by a time budget (the batch mechanics live in MediaBatchEngine).
 * A run is stale once the optimization settings change, and paused while optimization is off.
 *
 * @extends MediaBatchEngine<OptimizationRun>
 */
class OptimizeExistingMedia extends MediaBatchEngine
{
    public const string LOCK = 'media-optimization-batch';

    public function __construct(
        private OptimizeImage $optimizer,
        private RebuildMediaFile $rebuilder,
        private OptimizationRunStore $runs,
    ) {}

    /**
     * Start a fresh run (replacing any existing one) as the given user.
     *
     * @throws OptimizationDisabledException when image optimization is off
     */
    public function start(User $actor): OptimizationRun
    {
        Gate::forUser($actor)->authorize('optimize', Media::class);

        return $this->startWithoutActor();
    }

    /**
     * Start a fresh run without an actor (the CLI), replacing any existing one.
     *
     * @throws OptimizationDisabledException when image optimization is off (the run could never move); nothing is saved
     */
    public function startWithoutActor(): OptimizationRun
    {
        if (! config('media.optimize.enabled')) {
            throw new OptimizationDisabledException;
        }

        $fingerprint = $this->optimizer->fingerprint();

        $run = new OptimizationRun(
            id: (string) Str::ulid(),
            status: 'running',
            fingerprint: $fingerprint,
            cursor: 0,
            total: Media::query()->optimizationCandidates($fingerprint)->count(),
            counts: [],
            bytesBefore: 0,
            bytesAfter: 0,
            startedAt: now()->toImmutable(),
            heartbeatAt: now()->toImmutable(),
        );

        $this->runs->save($run);

        return $run;
    }

    protected function runs(): OptimizationRunStore
    {
        return $this->runs;
    }

    protected function lockName(): string
    {
        return self::LOCK;
    }

    protected function isProcessable(MediaBatchRun $run): bool
    {
        return ! $run->isStale($this->optimizer->fingerprint());
    }

    protected function mustPause(): bool
    {
        return ! config('media.optimize.enabled');
    }

    protected function next(MediaBatchRun $run): ?Media
    {
        return Media::query()->optimizationCandidates($run->fingerprint)->where('id', '>', $run->cursor)->first();
    }

    protected function processFile(MediaBatchRun $run, Media $media): void
    {
        [$outcome, $before, $after] = $this->optimizeSafely($media);
        $run->record($media, $outcome, $before, $after);
    }

    protected function recordAbandoned(MediaBatchRun $run, Media $media): void
    {
        $run->record($media, OptimizeOutcome::Unreadable, (int) $media->size, (int) $media->size);
    }

    /**
     * Rebuild one file from its source (the kept original, else the current file) with its stored edits.
     * Skipped outcomes write nothing; an existing original is never dropped. The row is rewritten only while it still
     * has the version loaded here: a file cropped or replaced meanwhile is `changed`, one deleted meanwhile `missing`.
     *
     * @return array{OptimizeOutcome, int, int} The outcome, and the size of the current file before and after.
     */
    public function optimizeOne(Media $media): array
    {
        $before = (int) $media->size;
        $version = (int) $media->version;

        try {
            return $this->rebuild($media, $before, $version);
        } catch (MediaChangedException $e) {
            return [$e->gone ? OptimizeOutcome::Missing : OptimizeOutcome::Changed, $before, $before];
        }
    }

    /**
     * optimizeOne() without the version check's outcome: throws when the row changed or went away meanwhile.
     *
     * @return array{OptimizeOutcome, int, int}
     *
     * @throws MediaChangedException
     */
    private function rebuild(Media $media, int $before, int $version): array
    {
        try {
            $source = MediaSource::for($media);
        } catch (RuntimeException) {
            return [OptimizeOutcome::Missing, $before, $before];
        }

        try {
            // Without the original the current file already has the edits baked in, so they are not applied again.
            $edits = $source->isOriginal ? ImageEdits::fromArray($media->edits) : null;
            $edits = $edits?->isEmpty() ? null : $edits;
            $result = $this->optimizer->handle($source->contents, $source->mime, $edits?->toArray());
        } catch (InvalidArgumentException) {
            return [OptimizeOutcome::Unreadable, $before, $before];
        }

        if ($result->failure !== null || in_array($result->mime, OptimizeImage::MUST_CONVERT, true)) {
            $outcome = $result->failure === 'too_large' ? OptimizeOutcome::TooLarge : OptimizeOutcome::Unreadable;

            return [$outcome, $before, $before];
        }

        if (! $source->isOriginal && $result->contents === $source->contents) {
            $media->edits = null;
            $this->rebuilder->markOptimized($media, $this->optimizer->fingerprint(), $version);

            return [OptimizeOutcome::AlreadyOptimal, $before, $before];
        }

        $keepOriginal = ! $source->isOriginal && config('media.optimize.enabled') && config('media.optimize.keep_originals');

        $this->rebuilder->store(
            $media,
            $result,
            $edits,
            newOriginal: $keepOriginal ? $source->contents : null,
            newOriginalMime: $keepOriginal ? $source->mime : null,
            keepEdits: $source->isOriginal,
            expectedVersion: $version,
        );

        return [OptimizeOutcome::Optimized, $before, (int) $media->size];
    }

    /**
     * optimizeOne(), with an unexpected error (e.g. a failed disk write, already rolled back by RebuildMediaFile)
     * reported and counted as unreadable, so one bad file can't stop the run at the same cursor forever.
     *
     * @return array{OptimizeOutcome, int, int}
     */
    private function optimizeSafely(Media $media): array
    {
        try {
            return $this->optimizeOne($media);
        } catch (Throwable $e) {
            report($e);

            return [OptimizeOutcome::Unreadable, (int) $media->size, (int) $media->size];
        }
    }
}
