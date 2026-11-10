{{--
    Details panel of the media library (screen 1, right).
    Expects: $media (the open file, with uploader and usages loaded), $folders (rows of folder + depth).
--}}
@php
    $canEdit = auth()->user()->can('update', $media);
    $locales = (array) config('app.available_locales');
    $primaryLocale = \App\Models\Media::primaryAltLocale();
    $translationLocales = array_diff_key($locales, [$primaryLocale => true]);
    $primaryAlt = $media->altFor($primaryLocale, fallback: false);
    $translationsFilled = count(array_filter(
        array_keys($translationLocales),
        fn (string $locale): bool => trim($media->altFor($locale, fallback: false)) !== '',
    ));
    $translationErrors = collect(array_keys($translationLocales))
        ->contains(fn (string $locale): bool => $errors->has('details.alt.'.$locale));
    $uploaded = $media->created_at?->tz(config('admin.timezone'))->isoFormat('LL');
    $convertedFrom = $media->convertedFrom();
    $folderPaths = [];
    $stack = [];

    foreach ($folders as $row) {
        $stack = array_slice($stack, 0, $row['depth']);
        $stack[] = $row['folder']->name;
        $folderPaths[$row['folder']->id] = implode(' / ', $stack);
    }
@endphp

<aside
    aria-label="{{ __('media.details.heading') }}"
    class="w-full shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-white xl:w-80 2xl:w-96 dark:border-white/10 dark:bg-zinc-900"
    wire:key="media-details-{{ $media->id }}"
    data-test="media-details"
>
    <div class="flex items-center justify-between border-b border-zinc-200 py-2 ps-4 pe-2 dark:border-white/10">
        <flux:heading size="sm" level="2">{{ __('media.details.heading') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="closeDetails" :aria-label="__('media.details.close')" />
    </div>

    <div class="flex aspect-[3/2] items-center justify-center bg-zinc-100 dark:bg-white/5">
        <a
            href="{{ $media->url() }}"
            target="_blank"
            rel="noopener"
            title="{{ __('media.details.open') }}"
            class="flex size-full cursor-zoom-in items-center justify-center focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent"
            data-test="media-details-preview"
        >
            <span class="sr-only">{{ __('media.details.open') }}</span>
            @if ($thumbUrl = $media->thumbUrl())
                <img src="{{ $thumbUrl }}" alt="{{ $media->altFor(app()->getLocale()) }}" class="size-full object-contain" />
            @else
                <span class="flex flex-col items-center gap-2 text-zinc-400 dark:text-zinc-500">
                    <flux:icon :name="$media->kind->icon()" variant="outline" class="size-12" />
                    <span class="text-xs font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $media->extension() }}</span>
                </span>
            @endif
        </a>
    </div>

    <form wire:submit="saveDetails">
        <div class="space-y-5 p-4">
            <div>
                <div class="text-sm font-semibold break-all text-zinc-800 dark:text-white">{{ $media->name }}</div>
                <dl class="mt-1.5 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[13px] text-zinc-700 dark:text-zinc-300">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('media.details.type') }}</dt>
                    <dd>{{ $convertedFrom ? __('media.details.type_converted', ['type' => $media->extension(), 'original' => $convertedFrom]) : $media->extension() }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('media.details.size') }}</dt>
                    <dd>{{ implode(' · ', array_filter([$media->humanSize(), $media->width && $media->height ? $media->width.' × '.$media->height : null])) }}</dd>
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('media.details.uploaded') }}</dt>
                    <dd>{{ $media->uploader ? __('media.details.uploaded_by', ['date' => $uploaded, 'name' => $media->uploader->name]) : $uploaded }}</dd>
                </dl>

                @if ($media->savedBytes() !== null)
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-green-50 px-2.5 py-2 text-[13px] text-green-800 dark:bg-green-400/10 dark:text-green-300">
                        <flux:icon name="check" variant="micro" class="shrink-0" />
                        <span>{{ __('media.details.optimized', [
                            'before' => App\Models\Media::formatBytes((int) $media->original_size),
                            'after' => $media->humanSize(),
                            'percent' => $media->savedPercent(),
                        ]) }}</span>
                    </div>
                @elseif (in_array($media->optimize_failure, ['too_large', 'unreadable'], true))
                    <div class="mt-3 flex items-center gap-2 rounded-lg bg-amber-50 px-2.5 py-2 text-[13px] text-amber-800 dark:bg-amber-400/10 dark:text-amber-300">
                        <flux:icon name="exclamation-triangle" variant="micro" class="shrink-0" />
                        <span>{{ $media->optimize_failure === 'too_large' ? __('media.details.not_optimized') : __('media.details.unreadable') }}</span>
                    </div>
                @endif
            </div>

            <flux:select wire:model="details.folder_id" :label="__('media.details.folder')" :disabled="! $canEdit">
                <flux:select.option value="">{{ __('media.index.folders.none') }}</flux:select.option>
                @foreach ($folderPaths as $id => $path)
                    <flux:select.option :value="$id" wire:key="details-folder-{{ $id }}">{{ $path }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="space-y-2">
                <flux:label for="alt-{{ $primaryLocale }}">
                    {{ __('media.details.alt') }}@if (count($locales) > 1) ({{ $locales[$primaryLocale] }})@endif
                </flux:label>
                <flux:textarea
                    id="alt-{{ $primaryLocale }}"
                    wire:model="details.alt.{{ $primaryLocale }}"
                    rows="2"
                    :disabled="! $canEdit"
                />
                <flux:error name="details.alt.{{ $primaryLocale }}" />
                <flux:text size="sm">{{ __('media.details.alt_hint') }}</flux:text>

                @if ($translationLocales !== [])
                    <div
                        x-data="{
                            open: @js($translationErrors),
                            locales: @js(array_keys($translationLocales)),
                            get filled() { return this.locales.filter((locale) => String($wire.details.alt?.[locale] ?? '').trim() !== '').length },
                        }"
                        data-test="alt-translations"
                    >
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            x-bind:aria-expanded="open ? 'true' : 'false'"
                            aria-controls="alt-translations"
                            class="flex w-full items-center gap-1.5 rounded-md py-1 text-start text-sm text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
                        >
                            <flux:icon.chevron-right variant="micro" class="transition-transform" x-bind:class="open && 'rotate-90'" />
                            <span>{{ __('media.details.translations') }}</span>
                            <span class="text-zinc-400 dark:text-zinc-500">·</span>
                            <span
                                class="text-zinc-500 dark:text-zinc-400"
                                x-text="@js(__('media.details.translations_filled', ['filled' => '__FILLED__', 'total' => count($translationLocales)])).replace('__FILLED__', filled)"
                            >{{ __('media.details.translations_filled', ['filled' => $translationsFilled, 'total' => count($translationLocales)]) }}</span>
                        </button>

                        <div id="alt-translations" x-show="open" x-cloak class="mt-2 space-y-3 border-s border-zinc-200 ps-3 dark:border-white/10">
                            <flux:text size="sm">{{ __('media.details.translations_hint') }}</flux:text>
                            @foreach ($translationLocales as $locale => $language)
                                <div wire:key="alt-field-{{ $locale }}" class="space-y-1">
                                    <flux:label for="alt-{{ $locale }}">{{ $language }}</flux:label>
                                    <flux:textarea
                                        id="alt-{{ $locale }}"
                                        wire:model="details.alt.{{ $locale }}"
                                        rows="2"
                                        :placeholder="$primaryAlt"
                                        :disabled="! $canEdit"
                                    />
                                    <flux:error name="details.alt.{{ $locale }}" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <flux:input wire:model="details.title" :label="__('media.details.title')" :disabled="! $canEdit" />

            <div class="space-y-1.5">
                <flux:label>{{ __('media.details.usage') }}</flux:label>
                @forelse ($media->usages as $usage)
                    <div class="text-[13px] text-zinc-700 dark:text-zinc-300" wire:key="usage-{{ $usage->id }}">
                        {{ __('media.details.usage_item', ['type' => class_basename($usage->usable_type), 'id' => $usage->usable_id]) }}
                    </div>
                @empty
                    <div><flux:badge size="sm" color="amber">{{ __('media.index.nowhere') }}</flux:badge></div>
                @endforelse
            </div>

            {{--
                The clipboard API needs HTTPS. Over plain HTTP the link is copied with execCommand('copy')
                from a hidden textarea, which browsers still allow inside a click. Only when both fail is the
                link selected in the field with a toast saying to copy it by hand.
            --}}
            <div
                class="flex gap-2"
                x-data="{
                    url: @js($media->url()),
                    copy() {
                        if (! navigator.clipboard?.writeText) {
                            return this.copyWithCommand() ? this.copied() : this.selectInstead();
                        }

                        navigator.clipboard.writeText(this.url).then(
                            () => this.copied(),
                            () => (this.copyWithCommand() ? this.copied() : this.selectInstead()),
                        );
                    },
                    copyWithCommand() {
                        const area = document.createElement('textarea');
                        area.value = this.url;
                        area.setAttribute('readonly', '');
                        area.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
                        document.body.appendChild(area);
                        area.select();

                        let done = false;

                        try {
                            done = document.execCommand('copy');
                        } catch {
                            done = false;
                        }

                        area.remove();

                        return done;
                    },
                    copied() {
                        this.$flux.toast({ text: @js(__('media.details.copied')), variant: 'success' });
                    },
                    selectInstead() {
                        const field = this.$root.querySelector('input');
                        field?.focus();
                        field?.select();
                        this.$flux.toast({ text: @js(__('media.details.copy_failed')), variant: 'danger' });
                    },
                }"
            >
                <flux:input size="sm" readonly :value="$media->url()" :aria-label="__('media.details.url')" class="min-w-0 flex-1" x-on:focus="$event.target.select()" />
                <flux:button size="sm" icon="link" x-on:click="copy()" data-test="media-details-copy">{{ __('media.details.copy') }}</flux:button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 border-t border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">
            @if ($canEdit)
                <flux:button type="submit" variant="primary" size="sm" data-test="media-details-save">{{ __('common.actions.save') }}</flux:button>
            @endif
            <flux:button size="sm" icon="arrow-top-right-on-square" :href="$media->url()" target="_blank" rel="noopener" :aria-label="__('media.details.open')" :tooltip="__('media.details.open')" data-test="media-details-open" />
            <flux:button size="sm" icon="arrow-down-tray" :href="$media->url()" download="{{ $media->name }}" :aria-label="__('media.details.download')" :tooltip="__('media.details.download')" />
            <flux:spacer />
            @can('delete', $media)
                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600! dark:text-red-400!" wire:click="confirmDelete({{ $media->id }})" data-test="media-details-delete">
                    {{ __('common.actions.delete') }}
                </flux:button>
            @endcan
        </div>
    </form>
</aside>
