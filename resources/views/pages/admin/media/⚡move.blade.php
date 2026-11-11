<?php

use App\Actions\Media\MoveMediaToDisk;
use App\Jobs\MoveMediaBatch;
use App\Livewire\Admin\MediaBatchPage;
use App\Models\Media;
use App\Models\User;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

new #[Title('media.move.title')] class extends MediaBatchPage {
    /**
     * Where the files go: the disk of the current move, else the configured storage.
     */
    #[Computed]
    public function target(): string
    {
        return $this->run?->target ?? (string) config('media.disk');
    }

    #[Computed]
    public function candidateCount(): int
    {
        return Media::query()->where('disk', '!=', $this->target)->count();
    }

    protected function engine(): MoveMediaToDisk
    {
        return app(MoveMediaToDisk::class);
    }

    protected function authorizeRun(): void
    {
        $this->authorize('moveToDisk', Media::class);
    }

    /**
     * A new move always goes to the configured storage.
     */
    protected function startRun(User $actor): bool
    {
        try {
            $this->engine()->start($actor, (string) config('media.disk'));
        } catch (InvalidArgumentException) {
            Flux::toast(variant: 'danger', text: __('media.move.not_configured'));

            return false;
        }

        return true;
    }

    protected function dispatchBatch(string $runId): void
    {
        MoveMediaBatch::dispatch($runId);
    }

    protected function refresh(): void
    {
        parent::refresh();
        unset($this->target, $this->candidateCount);
    }
}; ?>

@php
    $run = $this->run;
    $browserMode = ! $this->queueAvailable || $this->browserFallback;
    // keep-alive: browsers throttle polling in background tabs, and in browser mode the tab is what moves the files.
    $polling = $run?->status === 'running';
    $storage = __('site_settings.media.storage.'.$this->target);
    $outcomeColors = ['moved' => 'green', 'missing' => 'red', 'failed' => 'amber', 'changed' => 'zinc'];
@endphp

<div class="mx-auto w-full max-w-3xl space-y-6" data-test="media-move" @if ($polling) wire:poll.2s.keep-alive="tick" @endif>
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" icon="arrow-left" :href="route('admin.media.index')" wire:navigate :aria-label="__('media.optimize.back')" :tooltip="__('media.optimize.back')" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('media.move.title') }}</flux:heading>
            <flux:text>{{ __('media.move.description') }}</flux:text>
        </div>
    </div>

    @if ($run === null)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('media.move.idle_heading') }}</flux:heading>
            <flux:text>{{ __('media.move.target', ['storage' => $storage]) }}</flux:text>
            <flux:text>
                @if ($this->candidateCount > 0)
                    {{ trans_choice('media.move.candidates', $this->candidateCount, ['count' => $this->candidateCount]) }}
                @else
                    {{ __('media.move.none') }}
                @endif
            </flux:text>
            <flux:button variant="primary" wire:click="start" :disabled="$this->candidateCount === 0" data-test="move-start">
                {{ __('media.move.start') }}
            </flux:button>
        </flux:card>
    @else
        @php
            $processed = $run->processed();
            $percent = $run->total > 0 ? min(100, (int) floor($processed / $run->total * 100)) : 100;
            $secondsLeft = $run->status === 'running' ? $run->secondsLeft(max(0, $run->total - $processed)) : null;
        @endphp

        @if ($run->status === 'running' && $this->queueAvailable && $this->browserFallback)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="move-fallback">
                <flux:callout.text>{{ __('media.optimize.fallback') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">
                        {{ $run->status === 'done' ? __('media.move.done_heading') : trans_choice('media.optimize.progress', $run->total, ['processed' => $processed, 'total' => $run->total]) }}
                    </flux:heading>
                    <flux:badge :color="match ($run->status) { 'running' => 'blue', 'paused' => 'amber', default => 'green' }" size="sm">
                        {{ __("media.move.status.{$run->status}") }}
                    </flux:badge>
                </div>
                @if ($secondsLeft !== null)
                    <flux:text>{{ __('media.optimize.time_left', ['time' => \Carbon\CarbonInterval::seconds($secondsLeft)->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</flux:text>
                @endif
            </div>

            <flux:text>{{ __('media.move.target', ['storage' => $storage]) }}</flux:text>

            <flux:progress :value="$percent" data-test="move-progress" />

            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.move.stats.processed') }}</dt>
                    <dd class="font-medium">{{ $processed }} / {{ $run->total }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.move.stats.moved') }}</dt>
                    <dd class="font-medium">{{ $run->counts['moved'] ?? 0 }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.move.stats.size') }}</dt>
                    <dd class="font-medium" data-test="move-size">{{ Media::formatBytes($run->bytesMoved) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('media.move.stats.skipped') }}</dt>
                    <dd class="font-medium">{{ $run->skipped() }}</dd>
                </div>
            </dl>

            @if ($run->skipped() > 0)
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400" data-test="move-skipped">
                    @foreach (['missing', 'failed', 'changed'] as $reason)
                        @if (($run->counts[$reason] ?? 0) > 0)
                            <li>{{ trans_choice("media.move.skipped_reasons.{$reason}", $run->counts[$reason], ['count' => $run->counts[$reason]]) }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif

            @if ($run->status === 'done')
                @if ($this->candidateCount > 0)
                    <flux:text>{{ trans_choice('media.move.done_left', $this->candidateCount, ['count' => $this->candidateCount]) }}</flux:text>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($this->candidateCount > 0)
                        <flux:button variant="primary" wire:click="start" data-test="move-start-new">{{ __('media.move.start_new') }}</flux:button>
                    @endif
                    <flux:button wire:click="stop">{{ __('common.actions.close') }}</flux:button>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    @if ($run->status === 'running')
                        <flux:button icon="pause" wire:click="pause" data-test="move-pause">{{ __('media.optimize.pause') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon="play" wire:click="resume" data-test="move-resume">{{ __('media.optimize.resume') }}</flux:button>
                    @endif
                    <flux:button variant="danger" icon="stop" wire:click="$set('showStopModal', true)" data-test="move-stop">{{ __('media.optimize.stop') }}</flux:button>
                </div>
                <flux:text size="sm">{{ $browserMode ? __('media.optimize.tab_open_browser') : __('media.optimize.tab_open_queue') }}</flux:text>
                <flux:text size="sm">{{ __('media.move.uploads_note') }}</flux:text>
            @endif
        </flux:card>

        @if ($run->recent !== [])
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('media.optimize.recent') }}</flux:heading>
                <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="move-recent">
                    @foreach ($run->recent as $item)
                        <li wire:key="recent-{{ $loop->index }}-{{ $item['name'] }}" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate text-sm">{{ $item['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                <span>{{ Media::formatBytes((int) ($item['size'] ?? 0)) }}</span>
                                <flux:badge :color="$outcomeColors[$item['outcome']] ?? 'zinc'" size="sm">{{ __("media.move.outcomes.{$item['outcome']}") }}</flux:badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif
    @endif

    <x-confirm-modal wire:model.self="showStopModal" :heading="__('media.move.stop_heading')" :confirm-label="__('media.optimize.stop')" confirm-action="stop">
        {{ __('media.move.stop_body') }}
    </x-confirm-modal>
</div>
