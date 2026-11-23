<?php

use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Jobs\MoveMediaBatch;
use Hata\Livewire\Admin\MediaBatchPage;
use Hata\Models\Media;
use Hata\Contracts\AdminUser;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

new #[Layout('hata::layouts.app')] #[Title('hata::media.move.title')] class extends MediaBatchPage {
    /**
     * The storage the candidates are counted against: the target of a move that is going on, else (no move, or a
     * finished one) the configured storage, which is also where a new move goes. For "this server" (`public`) a row is
     * a candidate only when it is not where an upload would put it (MoveMediaToDisk::candidates()).
     */
    #[Computed]
    public function target(): string
    {
        $run = $this->run;

        return $run !== null && $run->status !== 'done' ? $run->target : (string) config('hata.media.disk');
    }

    /**
     * None while the target is not a storage files can be moved to (the private `local` mode set in `.env`), so the
     * page never offers a move the engine refuses.
     */
    #[Computed]
    public function candidateCount(): int
    {
        if (! in_array($this->target, MoveMediaToDisk::TARGETS, true)) {
            return 0;
        }

        return MoveMediaToDisk::candidates($this->target)->count();
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
     * A new move goes to the configured storage (the target the page counts candidates against).
     */
    protected function startRun(AdminUser $actor): bool
    {
        try {
            $this->engine()->start($actor, $this->target);
        } catch (InvalidArgumentException) {
            Flux::toast(variant: 'danger', text: __('hata::media.move.not_configured'));

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
    // The progress card names the disk of its own move; the idle card the one a new move would use.
    $storage = __('hata::site_settings.media.storage.'.($run?->target ?? $this->target));
    $outcomeColors = ['moved' => 'green', 'missing' => 'red', 'failed' => 'amber', 'changed' => 'zinc'];
@endphp

<div class="w-full max-w-3xl space-y-6" data-test="media-move" @if ($polling) wire:poll.2s.keep-alive="tick" @endif>
    <div class="flex items-center gap-3">
        <flux:button variant="ghost" icon="arrow-left" :href="route('admin.media.index')" wire:navigate :aria-label="__('hata::media.batch.back')" :tooltip="__('hata::media.batch.back')" />
        <div class="min-w-0">
            <flux:heading size="xl" level="1">{{ __('hata::media.move.title') }}</flux:heading>
            <flux:text>{{ __('hata::media.move.description') }}</flux:text>
        </div>
    </div>

    @if ($run === null)
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('hata::media.move.idle_heading') }}</flux:heading>
            <flux:text>{{ __('hata::media.move.target', ['storage' => $storage]) }}</flux:text>
            <flux:text>
                @if ($this->candidateCount > 0)
                    {{ trans_choice('hata::media.move.candidates', $this->candidateCount, ['count' => $this->candidateCount]) }}
                @else
                    {{ __('hata::media.move.none') }}
                @endif
            </flux:text>
            <flux:button variant="primary" wire:click="start" :disabled="$this->candidateCount === 0" data-test="move-start">
                {{ __('hata::media.move.start') }}
            </flux:button>
            <flux:text size="sm">{{ __('hata::media.move.large_files_note') }}</flux:text>
        </flux:card>
    @else
        @php
            $processed = $run->processed();
            $percent = $run->total > 0 ? min(100, (int) floor($processed / $run->total * 100)) : 100;
            $secondsLeft = $run->status === 'running' ? $run->secondsLeft(max(0, $run->total - $processed)) : null;
        @endphp

        @if ($run->status === 'running' && $this->queueAvailable && $this->browserFallback)
            <flux:callout variant="warning" icon="exclamation-triangle" data-test="move-fallback">
                <flux:callout.text>{{ __('hata::media.batch.fallback') }}</flux:callout.text>
            </flux:callout>
        @endif

        <flux:card class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <flux:heading size="lg">
                        {{ $run->status === 'done' ? __('hata::media.move.done_heading') : trans_choice('hata::media.batch.progress', $run->total, ['processed' => $processed, 'total' => $run->total]) }}
                    </flux:heading>
                    <flux:badge :color="match ($run->status) { 'running' => 'blue', 'paused' => 'amber', default => 'green' }" size="sm">
                        {{ __("hata::media.move.status.{$run->status}") }}
                    </flux:badge>
                </div>
                @if ($secondsLeft !== null)
                    <flux:text>{{ __('hata::media.batch.time_left', ['time' => \Carbon\CarbonInterval::seconds($secondsLeft)->cascade()->forHumans(['parts' => 2, 'short' => true])]) }}</flux:text>
                @endif
            </div>

            <flux:text>{{ __('hata::media.move.target', ['storage' => $storage]) }}</flux:text>

            <flux:progress :value="$percent" data-test="move-progress" />

            <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('hata::media.move.stats.processed') }}</dt>
                    <dd class="font-medium">{{ $processed }} / {{ $run->total }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('hata::media.move.stats.moved') }}</dt>
                    <dd class="font-medium">{{ $run->counts['moved'] ?? 0 }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('hata::media.move.stats.size') }}</dt>
                    <dd class="font-medium" data-test="move-size">{{ Media::formatBytes($run->bytesMoved) }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('hata::media.move.stats.skipped') }}</dt>
                    <dd class="font-medium">{{ $run->skipped() }}</dd>
                </div>
            </dl>

            @if ($run->skipped() > 0)
                <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400" data-test="move-skipped">
                    @foreach (['missing', 'failed', 'changed'] as $reason)
                        @if (($run->counts[$reason] ?? 0) > 0)
                            <li>{{ trans_choice("hata::media.move.skipped_reasons.{$reason}", $run->counts[$reason], ['count' => $run->counts[$reason]]) }}</li>
                        @endif
                    @endforeach
                </ul>
            @endif

            @if ($run->status === 'done')
                @if ($this->candidateCount > 0)
                    <flux:text>{{ trans_choice('hata::media.move.done_left', $this->candidateCount, ['count' => $this->candidateCount]) }}</flux:text>
                @endif
                <div class="flex flex-wrap gap-2">
                    @if ($this->candidateCount > 0)
                        <flux:button variant="primary" wire:click="start" data-test="move-start-new">{{ __('hata::media.move.start_new') }}</flux:button>
                    @endif
                    <flux:button wire:click="stop">{{ __('hata::common.actions.close') }}</flux:button>
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    @if ($run->status === 'running')
                        <flux:button icon="pause" wire:click="pause" data-test="move-pause">{{ __('hata::media.batch.pause') }}</flux:button>
                    @else
                        <flux:button variant="primary" icon="play" wire:click="resume" data-test="move-resume">{{ __('hata::media.batch.resume') }}</flux:button>
                    @endif
                    <flux:button variant="danger" icon="stop" wire:click="$set('showStopModal', true)" data-test="move-stop">{{ __('hata::media.batch.stop') }}</flux:button>
                </div>
                <flux:text size="sm">{{ $browserMode ? __('hata::media.batch.tab_open_browser') : __('hata::media.batch.tab_open_queue') }}</flux:text>
                <flux:text size="sm">{{ __('hata::media.move.uploads_note') }}</flux:text>
                <flux:text size="sm">{{ __('hata::media.move.large_files_note') }}</flux:text>
            @endif
        </flux:card>

        @if ($run->recent !== [])
            <flux:card class="space-y-3">
                <flux:heading size="lg">{{ __('hata::media.batch.recent') }}</flux:heading>
                <ul class="divide-y divide-zinc-200 dark:divide-white/10" data-test="move-recent">
                    @foreach ($run->recent as $item)
                        <li wire:key="recent-{{ $loop->index }}-{{ $item['name'] }}" class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate text-sm">{{ $item['name'] }}</span>
                            <span class="flex shrink-0 items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                <span>{{ Media::formatBytes((int) ($item['size'] ?? 0)) }}</span>
                                <flux:badge :color="$outcomeColors[$item['outcome']] ?? 'zinc'" size="sm">{{ __("hata::media.move.outcomes.{$item['outcome']}") }}</flux:badge>
                            </span>
                        </li>
                    @endforeach
                </ul>
            </flux:card>
        @endif
    @endif

    <x-hata::confirm-modal wire:model.self="showStopModal" :heading="__('hata::media.move.stop_heading')" :confirm-label="__('hata::media.batch.stop')" confirm-action="stop">
        {{ __('hata::media.move.stop_body') }}
    </x-hata::confirm-modal>
</div>
