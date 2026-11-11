<?php

use App\Actions\Media\OptimizeExistingMedia;
use App\Actions\Media\OptimizeImage;
use App\Exceptions\OptimizationDisabledException;
use App\Jobs\OptimizeExistingMediaBatch;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('media.optimize.title')] class extends Component {
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

    public function mount(): void
    {
        $this->authorize('optimize', Media::class);

        // Without a queue the tab drives the run; with one, tick() decides from the queue's heartbeat.
        $this->browserFallback = ! $this->queueAvailable;
    }

    #[Computed]
    public function run(): ?OptimizationRun
    {
        return app(OptimizationRunStore::class)->get();
    }

    #[Computed]
    public function candidateCount(): int
    {
        return Media::query()->optimizationCandidates(app(OptimizeImage::class)->fingerprint())->count();
    }

    #[Computed]
    public function queueAvailable(): bool
    {
        return config('queue.default') !== 'sync';
    }

    #[Computed]
    public function isStale(): bool
    {
        return $this->run?->isStale(app(OptimizeImage::class)->fingerprint()) ?? false;
    }

    public function start(): void
    {
        $this->authorize('optimize', Media::class);

        try {
            app(OptimizeExistingMedia::class)->start($this->actor());
        } catch (OptimizationDisabledException) {
            Flux::toast(variant: 'danger', text: __('media.optimize.disabled'));

            return;
        }

        $this->browserFallback = ! $this->queueAvailable;
        $this->dispatchWhenQueued();
        $this->refresh();
    }

    public function startOver(): void
    {
        $this->start();
    }

    public function pause(): void
    {
        $this->authorize('optimize', Media::class);

        app(OptimizeExistingMedia::class)->pause();
        $this->refresh();
    }

    public function resume(): void
    {
        $this->authorize('optimize', Media::class);

        app(OptimizeExistingMedia::class)->resume();
        $this->browserFallback = ! $this->queueAvailable;
        $this->dispatchWhenQueued();
        $this->refresh();
    }

    public function stop(): void
    {
        $this->authorize('optimize', Media::class);

        app(OptimizeExistingMedia::class)->stop();
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
        $this->authorize('optimize', Media::class);

        $run = $this->run;

        if ($run === null || $run->status !== 'running') {
            $this->refresh();

            return;
        }

        $engine = app(OptimizeExistingMedia::class);

        // With optimization off a batch only pauses the run (the engine does that), so the page shows it as paused.
        if (! $this->queueAvailable || $this->browserFallback || ! config('media.optimize.enabled')) {
            $engine->processBatch('browser', self::BROWSER_BUDGET);
        } elseif (! $engine->isBatchRunning() && ($run->heartbeatAt ?? $run->startedAt)->lt(now()->subSeconds(self::FALLBACK_AFTER))) {
            $this->browserFallback = true;
            $engine->processBatch('browser', self::BROWSER_BUDGET);
        }

        $this->refresh();
    }

    private function dispatchWhenQueued(): void
    {
        if ($this->queueAvailable) {
            OptimizeExistingMediaBatch::dispatch();
        }
    }

    private function refresh(): void
    {
        unset($this->run, $this->candidateCount, $this->isStale);
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $run = $this->run;
    $enabled = (bool) config('media.optimize.enabled');
    $stale = $this->isStale;
    $browserMode = ! $this->queueAvailable || $this->browserFallback;
    // Keeps polling while optimization is off, so the next tick pauses the run. keep-alive: browsers throttle polling
    // in background tabs, and in browser mode the tab is what processes the files.
    $polling = $run?->status === 'running' && ! $stale;
    $size = fn (int $bytes) => Media::formatBytes($bytes);
    $outcomeColors = ['optimized' => 'green', 'already_optimal' => 'zinc', 'too_large' => 'amber', 'unreadable' => 'amber', 'missing' => 'red'];
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6" data-test="media-optimize" @if ($polling) wire:poll.2s.keep-alive="tick" @endif>
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" icon="arrow-left" :href="route('admin.media.index')" wire:navigate :aria-label="__('media.optimize.back')" :tooltip="__('media.optimize.back')" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('media.optimize.title') }}</flux:heading>
            <flux:text>{{ __('media.optimize.description') }}</flux:text>
        </div>
    </div>

    @unless ($enabled)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('media.optimize.disabled')" data-test="optimize-disabled">
            <flux:callout.text>{{ __('media.optimize.disabled_body') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('admin.settings.edit', ['tab' => 'media'])" wire:navigate>{{ __('media.optimize.open_settings') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endunless

    @if ($run === null)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('media.optimize.idle_heading') }}</flux:heading>
            @if ($enabled)
                <flux:text>
                    @if ($this->candidateCount > 0)
                        {{ trans_choice('media.optimize.candidates', $this->candidateCount, ['count' => $this->candidateCount]) }}
                    @else
                        {{ __('media.optimize.none') }}
                    @endif
                </flux:text>
            @endif
            <flux:button variant="primary" wire:click="start" :disabled="! $enabled || $this->candidateCount === 0" data-test="optimize-start">
                {{ __('media.optimize.start') }}
            </flux:button>
        </flux:card>
    @else
        @php
            $processed = $run->processed();
            $percent = $run->total > 0 ? min(100, (int) floor($processed / $run->total * 100)) : 100;
            $saved = $run->bytesSaved();
            $averageSaving = $run->bytesBefore > 0 ? (int) round($saved / $run->bytesBefore * 100) : 0;
            $secondsLeft = $run->status === 'running' ? $run->secondsLeft(max(0, $run->total - $processed)) : null;
        @endphp

        @if ($stale)
            <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('media.optimize.stale_heading')" data-test="optimize-stale">
                <flux:callout.text>{{ __('media.optimize.stale_body') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button wire:click="startOver" :disabled="! $enabled">{{ __('media.optimize.start_over') }}</flux:button>
                </x-slot>
            </flux:callout>
        @elseif ($run->status === 'running' && $this->queueAvailable && $this->browserFallback)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="optimize-fallback">
                <flux:callout.text>{{ __('media.optimize.fallback') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">
                        {{ $run->status === 'done' ? __('media.optimize.done_heading') : trans_choice('media.optimize.progress', $run->total, ['processed' => $processed, 'total' => $run->total]) }}
                    </flux:heading>
                    <flux:badge :color="match ($run->status) { 'running' => 'blue', 'paused' => 'amber', default => 'green' }" size="sm">
                        {{ __("media.optimize.status.{$run->status}") }}
                    </flux:badge>
                </div>
                @if ($secondsLeft !== null && ! $stale)
                    <flux:text>{{ __('media.optimize.time_left', ['time' => \Carbon\CarbonInterval::seconds($secondsLeft)->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</flux:text>
                @endif
            </div>

            <flux:progress :value="$percent" data-test="optimize-progress" />

            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.optimize.stats.processed') }}</dt>
                    <dd class="font-medium">{{ $processed }} / {{ $run->total }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.optimize.stats.saved') }}</dt>
                    <dd class="font-medium" data-test="optimize-saved">{{ $saved >= 0 ? $size($saved) : '+'.$size(-$saved) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.optimize.stats.average') }}</dt>
                    <dd class="font-medium">{{ $averageSaving }}%</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.optimize.stats.skipped') }}</dt>
                    <dd class="font-medium">{{ $run->skipped() }}</dd>
                </div>
            </dl>

            @if ($run->skipped() > 0)
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400" data-test="optimize-skipped">
                    @foreach (['already_optimal', 'too_large', 'unreadable', 'missing'] as $reason)
                        @if (($run->counts[$reason] ?? 0) > 0)
                            <li>{{ trans_choice("media.optimize.skipped_reasons.{$reason}", $run->counts[$reason], ['count' => $run->counts[$reason]]) }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif

            @if ($run->status === 'done')
                @if ($this->candidateCount > 0)
                    <flux:text>{{ trans_choice('media.optimize.done_left', $this->candidateCount, ['count' => $this->candidateCount]) }}</flux:text>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($this->candidateCount > 0)
                        <flux:button variant="primary" wire:click="start" :disabled="! $enabled" data-test="optimize-start-new">{{ __('media.optimize.start_new') }}</flux:button>
                    @endif
                    <flux:button wire:click="stop">{{ __('common.actions.close') }}</flux:button>
                </div>
            @elseif (! $stale)
                <div class="flex flex-wrap gap-2">
                    @if ($run->status === 'running')
                        <flux:button icon="pause" wire:click="pause" data-test="optimize-pause">{{ __('media.optimize.pause') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon="play" wire:click="resume" :disabled="! $enabled" data-test="optimize-resume">{{ __('media.optimize.resume') }}</flux:button>
                    @endif
                    <flux:button variant="danger" icon="stop" wire:click="$set('showStopModal', true)" data-test="optimize-stop">{{ __('media.optimize.stop') }}</flux:button>
                </div>
                <flux:text size="sm">{{ $browserMode ? __('media.optimize.tab_open_browser') : __('media.optimize.tab_open_queue') }}</flux:text>
            @else
                <div>
                    <flux:button wire:click="stop">{{ __('media.optimize.stop') }}</flux:button>
                </div>
            @endif
        </flux:card>

        @if ($run->recent !== [])
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('media.optimize.recent') }}</flux:heading>
                <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="optimize-recent">
                    @foreach ($run->recent as $item)
                        <li wire:key="recent-{{ $loop->index }}-{{ $item['name'] }}" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate text-sm">{{ $item['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                @if ($item['outcome'] === 'optimized')
                                    <span>{{ $size($item['before']) }} → {{ $size($item['after']) }}</span>
                                @endif
                                <flux:badge :color="$outcomeColors[$item['outcome']] ?? 'zinc'" size="sm">{{ __("media.optimize.outcomes.{$item['outcome']}") }}</flux:badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif
    @endif

    <x-confirm-modal wire:model.self="showStopModal" :heading="__('media.optimize.stop_heading')" :confirm-label="__('media.optimize.stop')" confirm-action="stop">
        {{ __('media.optimize.stop_body') }}
    </x-confirm-modal>
</div>
