<?php

namespace App\Livewire\Admin;

use App\Actions\Media\MediaBatchEngine;
use App\Models\User;
use App\Support\Media\MediaBatchRun;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Base for the pages that drive a media run (optimize existing files, move files between disks), decision 48: with a
 * queue the run goes to a self-chaining job and the page only watches; without one the open tab processes batches
 * while it polls. With a queue, the tab takes over after FALLBACK_AFTER seconds without a heartbeat while no batch
 * holds the lock. A class rather than a trait: PHPStan doesn't analyse SFC files (decision 32).
 *
 * @property-read MediaBatchRun|null $run
 * @property-read bool $queueAvailable
 */
abstract class MediaBatchPage extends Component
{
    /**
     * Seconds without a sign of life from the queue before the open tab takes over.
     */
    public const int FALLBACK_AFTER = 30;

    /**
     * Seconds of work per request while the tab processes files itself.
     */
    public const float BROWSER_BUDGET = 5.0;

    /**
     * True once the tab processes the run itself although a queue is configured (the queue didn't pick it up).
     */
    #[Locked]
    public bool $browserFallback = false;

    public bool $showStopModal = false;

    /**
     * @return MediaBatchEngine<covariant MediaBatchRun>
     */
    abstract protected function engine(): MediaBatchEngine;

    /**
     * Check the current user may drive this run (on mount and on every action).
     */
    abstract protected function authorizeRun(): void;

    /**
     * Start a fresh run as the actor; false when it was refused (the page has told the user why).
     */
    abstract protected function startRun(User $actor): bool;

    /**
     * Queue the first batch of the run.
     */
    abstract protected function dispatchBatch(string $runId): void;

    /**
     * Forget the computed values that change with the run (subclasses add theirs).
     */
    protected function refresh(): void
    {
        unset($this->run);
    }

    /**
     * Whether a tick must process a batch here even while the queue drives the run (e.g. so the engine pauses a run
     * whose feature was turned off, and the page shows it).
     */
    protected function mustProcessHere(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->authorizeRun();

        // Without a queue the tab drives the run; with one, tick() decides from the queue's heartbeat.
        $this->browserFallback = ! $this->queueAvailable;
    }

    #[Computed]
    public function run(): ?MediaBatchRun
    {
        return $this->engine()->current();
    }

    #[Computed]
    public function queueAvailable(): bool
    {
        return config('queue.default') !== 'sync';
    }

    public function start(): void
    {
        $this->authorizeRun();

        if (! $this->startRun($this->actor())) {
            return;
        }

        $this->browserFallback = ! $this->queueAvailable;
        $this->dispatchWhenQueued();
        $this->refresh();
    }

    public function pause(): void
    {
        $this->authorizeRun();

        $this->engine()->pause();
        $this->refresh();
    }

    public function resume(): void
    {
        $this->authorizeRun();

        $this->engine()->resume();
        $this->browserFallback = ! $this->queueAvailable;
        $this->dispatchWhenQueued();
        $this->refresh();
    }

    public function stop(): void
    {
        $this->authorizeRun();

        $this->engine()->stop();
        $this->showStopModal = false;
        $this->browserFallback = ! $this->queueAvailable;
        $this->refresh();
    }

    /**
     * The poll target while a run is going: processes a batch here when no queue is available (or it isn't working),
     * otherwise only refreshes and watches the queue's heartbeat.
     */
    public function tick(): void
    {
        $this->authorizeRun();

        $run = $this->run;

        if ($run === null || $run->status !== 'running') {
            $this->refresh();

            return;
        }

        $engine = $this->engine();

        if (! $this->queueAvailable || $this->browserFallback || $this->mustProcessHere()) {
            $engine->processBatch('browser', self::BROWSER_BUDGET);
        } elseif (! $engine->isBatchRunning() && ($run->heartbeatAt ?? $run->startedAt)->lt(now()->subSeconds(self::FALLBACK_AFTER))) {
            $this->browserFallback = true;
            $engine->processBatch('browser', self::BROWSER_BUDGET);
        }

        $this->refresh();
    }

    private function dispatchWhenQueued(): void
    {
        $run = $this->engine()->current();

        if ($this->queueAvailable && $run !== null) {
            $this->dispatchBatch($run->id);
        }
    }

    protected function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
