<?php

use Hata\Livewire\Admin\Settings\MediaSection;
use Hata\Models\Media;
use Hata\Support\Media\DirectServing;
use Hata\Support\Media\MediaDisks;

new class extends MediaSection {
}; ?>

<x-hata::admin.settings-section :secrets="['media_s3_secret']">
    <flux:heading level="2">{{ __('hata::site_settings.media.optimize_section') }}</flux:heading>

    <flux:switch wire:model.live="values.media_optimize" :label="__('hata::site_settings.media.optimize')" :description="__('hata::site_settings.media.optimize_description')" align="left" />

    @if (($values['media_optimize'] ?? false))
        <flux:select wire:model="values.media_max_side" :label="__('hata::site_settings.media.max_side')" :description="__('hata::site_settings.media.max_side_description')" class="max-w-xs">
            @foreach (Hata\Livewire\Admin\Settings\MediaSection::MEDIA_MAX_SIDES as $side)
                <flux:select.option :value="$side" wire:key="max-side-{{ $side }}">{{ __("hata::site_settings.media.max_side_{$side}") }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:field x-data="{ quality: $wire.values.media_quality }">
            <div class="flex items-center justify-between">
                <label for="media-quality" class="text-sm font-medium text-zinc-800 dark:text-white">{{ __('hata::site_settings.media.quality') }}</label>
                <span class="text-sm font-medium tabular-nums text-zinc-800 dark:text-white" data-test="media-quality-value" x-text="quality">{{ $values['media_quality'] }}</span>
            </div>
            <input id="media-quality" type="range" min="50" max="100" step="1" wire:model="values.media_quality" x-model="quality" class="w-full accent-accent" />
            <div class="flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ __('hata::site_settings.media.quality_smaller') }}</span>
                <span>{{ __('hata::site_settings.media.quality_better') }}</span>
            </div>
            <flux:error name="values.media_quality" />
        </flux:field>

        <flux:radio.group wire:model="values.media_format" :label="__('hata::site_settings.media.format')">
            <flux:radio value="webp" :label="__('hata::site_settings.media.format_webp')" :description="__('hata::site_settings.media.format_webp_description')" />
            <flux:radio value="original" :label="__('hata::site_settings.media.format_original')" />
            {{-- AVIF is display-only for now: OptimizeImage accepts webp and original. --}}
            <flux:radio
                value="avif"
                disabled
                :label="__('hata::site_settings.media.format_avif')"
                :description="$this->avifSupported ? __('hata::site_settings.media.format_avif_later') : __('hata::site_settings.media.format_avif_unsupported')"
            />
        </flux:radio.group>

        <flux:switch wire:model="values.media_strip_exif" :label="__('hata::site_settings.media.strip_exif')" :description="__('hata::site_settings.media.strip_exif_description')" align="left" />
        <flux:switch wire:model="values.media_client_resize" :label="__('hata::site_settings.media.client_resize')" :description="__('hata::site_settings.media.client_resize_description')" align="left" />
        <flux:switch wire:model="values.media_keep_originals" :label="__('hata::site_settings.media.keep_originals')" :description="__('hata::site_settings.media.keep_originals_description')" align="left" />
        @can('media.edit')
            @php($card = $this->optimizationCard)
            <flux:callout icon="arrow-path" class="max-w-xl" data-test="optimize-existing-card">
                <flux:callout.heading>{{ __('hata::site_settings.media.existing_heading') }}</flux:callout.heading>
                <flux:callout.text>
                    @if (! $card['enabled'])
                        <span data-test="optimize-existing-unsaved">{{ __('hata::site_settings.media.existing_save_first') }}</span>
                    @elseif ($card['run'] !== null)
                        <span data-test="optimize-existing-progress">{{ __("hata::media.optimize.status.{$card['run']->status}") }}: {{ trans_choice('hata::media.batch.progress', $card['run']->total, ['processed' => $card['run']->processed(), 'total' => $card['run']->total]) }}</span>
                    @elseif ($card['count'] > 0)
                        <span><span data-test="optimize-existing-count">{{ $card['count'] }}</span> {{ trans_choice('hata::site_settings.media.existing_candidates', $card['count']) }}</span>
                        <span data-test="optimize-existing-saving">{{ __('hata::site_settings.media.existing_saving', ['size' => $card['saving']]) }}</span>
                    @else
                        {{ __('hata::site_settings.media.existing_none') }}
                    @endif
                </flux:callout.text>
                <x-slot name="actions">
                    @if ($card['enabled'])<flux:button size="sm" :href="route('admin.media.optimize')" wire:navigate>{{ __('hata::site_settings.media.existing_button') }}</flux:button>@endif
                </x-slot>
            </flux:callout>
        @endcan
    @endif

    <flux:separator />

    <flux:heading level="2">{{ __('hata::site_settings.media.limits_section') }}</flux:heading>

    <div class="grid gap-6 sm:grid-cols-2">
        <flux:input wire:model="values.media_max_image_mb" type="number" min="1" max="512" :label="__('hata::site_settings.media.max_image_mb')" required />
        <flux:input wire:model="values.media_max_document_mb" type="number" min="1" max="512" :label="__('hata::site_settings.media.max_document_mb')" required />
    </div>

    @if ($this->serverCap !== null)
        <flux:text class="text-xs" data-test="media-server-cap">{{ __('hata::site_settings.media.server_cap', ['size' => $this->serverCap]) }}</flux:text>
    @endif

    <flux:separator />

    <flux:heading level="2">{{ __('hata::site_settings.media.storage.section') }}</flux:heading>

    <flux:radio.group wire:model.live="values.media_storage" :label="__('hata::site_settings.media.storage.where')" :description="__('hata::site_settings.media.storage.where_description')">
        <flux:radio value="public" :label="__('hata::site_settings.media.storage.public')" :description="__('hata::site_settings.media.storage.public_description')" />
        <flux:radio value="s3" :label="__('hata::site_settings.media.storage.s3')" :description="__('hata::site_settings.media.storage.s3_description')" />
    </flux:radio.group>
    <flux:error name="values.media_storage" />

    @if (MediaDisks::publishes((string) config('hata.media.disk')))
        @php($directState = $this->directState)
        <div class="flex flex-wrap items-center gap-3" x-data="{ checking: false }" x-on:media-direct-checked.window="$wire.$refresh()" data-test="direct-status-{{ $directState }}">
            <flux:text @class(['max-w-prose', 'text-amber-600 dark:text-amber-400' => in_array($directState, ['link_missing', 'unreachable'], true)])>{{ __('hata::site_settings.media.storage.direct.'.$directState, ['url' => app(DirectServing::class)->probeUrl()]) }}</flux:text>

            @if ($directState === 'link_missing')
                <flux:button size="sm" wire:click="createStorageLink" data-test="create-storage-link">{{ __('hata::site_settings.media.storage.direct.create_link') }}</flux:button>
            @elseif ($directState !== 'direct')
                <flux:button size="sm" x-bind:disabled="checking" x-on:click="checking = true; Promise.resolve(window.checkMediaDirect?.(true)).finally(() => checking = false)" data-test="check-direct">{{ __('hata::site_settings.media.storage.direct.check_again') }}</flux:button>
            @endif
        </div>
    @endif

    @if ($this->filesOnOtherDisk > 0)
        <flux:callout icon="information-circle" variant="secondary" data-test="files-on-other-disk">
            <flux:callout.text>
                {{ trans_choice('hata::site_settings.media.storage.other_disk', $this->filesOnOtherDisk, ['count' => $this->filesOnOtherDisk]) }}
                @can('moveToDisk', Media::class)
                    <flux:link :href="route('admin.media.move')" wire:navigate data-test="move-files-link">{{ __('hata::site_settings.media.storage.move_link', ['storage' => __('hata::site_settings.media.storage.'.config('hata.media.disk'))]) }}</flux:link>
                @endcan
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (($values['media_storage'] ?? 'public') === 's3' || $this->hasS3Files)
    <div class="space-y-6" data-test="s3-fields">
        <div class="grid items-start gap-6 sm:grid-cols-2">
            <flux:input wire:model="values.media_s3_endpoint" :label="__('hata::site_settings.media.storage.endpoint')" :description:trailing="__('hata::site_settings.media.storage.endpoint_description')" placeholder="https://s3.example.com" autocomplete="off" />
            <flux:input wire:model="values.media_s3_region" :label="__('hata::site_settings.media.storage.region')" placeholder="us-east-1" autocomplete="off" />
        </div>

        <div class="grid items-start gap-6 sm:grid-cols-2">
            <flux:input wire:model="values.media_s3_bucket" :label="__('hata::site_settings.media.storage.bucket')" autocomplete="off" />
            <flux:input wire:model="values.media_s3_prefix" :label="__('hata::site_settings.media.storage.prefix')" :description:trailing="__('hata::site_settings.media.storage.prefix_description')" placeholder="prod" autocomplete="off" />
        </div>

        <div class="grid items-start gap-6 sm:grid-cols-2">
            <flux:input wire:model="values.media_s3_key" :label="__('hata::site_settings.media.storage.key')" autocomplete="off" />
            <flux:input
                wire:model="values.media_s3_secret"
                type="password"
                viewable
                :label="__('hata::site_settings.media.storage.secret')"
                :description:trailing="$this->hasStoredS3Secret ? __('hata::site_settings.media.storage.secret_keep') : ($this->hasEnvS3Secret ? __('hata::site_settings.media.storage.secret_env') : null)"
                autocomplete="new-password"
            />
        </div>

        <flux:switch wire:model="values.media_s3_path_style" :label="__('hata::site_settings.media.storage.path_style')" :description="__('hata::site_settings.media.storage.path_style_description')" align="left" />

        <flux:input wire:model="values.media_s3_public_url" :label="__('hata::site_settings.media.storage.public_url')" :description:trailing="__('hata::site_settings.media.storage.public_url_description')" placeholder="https://cdn.example.com" autocomplete="off" />
    </div>
    @endif

    @if (($values['media_storage'] ?? 'public') === 's3' || $this->hasS3Files)
        @if ($storageTest !== null)
            <flux:callout
                :icon="$storageTest['ok'] ? 'check-circle' : 'exclamation-triangle'"
                :variant="$storageTest['ok'] ? 'success' : 'danger'"
                data-test="storage-test-result"
            >
                <flux:callout.text>{{ $storageTest['message'] }}</flux:callout.text>
            </flux:callout>
        @endif

        <div>
            <flux:button type="button" variant="ghost" icon="signal" wire:click="testStorageConnection" data-test="test-storage-button">
                {{ __('hata::site_settings.media.storage.test') }}
            </flux:button>
        </div>
    @endif

    <x-hata::confirm-modal
        wire:model.self="showS3ChangeModal"
        :heading="__('hata::site_settings.media.storage.change_heading')"
        :confirm-label="__('hata::site_settings.media.storage.change_confirm')"
        confirm-action="confirmS3Change"
    >
        {{ __('hata::site_settings.media.storage.change_body') }}
    </x-hata::confirm-modal>
</x-hata::admin.settings-section>
