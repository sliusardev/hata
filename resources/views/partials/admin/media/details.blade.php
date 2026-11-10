{{--
    Details panel of the media library (screen 1, right).
    Expects: $media (the open file, with uploader and usages loaded), $folders (rows of folder + depth).
--}}
@php
    $canEdit = auth()->user()->can('update', $media);
    $locales = (array) config('app.available_locales');
    $currentLocale = array_key_exists(app()->getLocale(), $locales) ? app()->getLocale() : array_key_first($locales);
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
        @if ($thumbUrl = $media->thumbUrl())
            <img src="{{ $thumbUrl }}" alt="{{ $media->altFor(app()->getLocale()) }}" class="size-full object-contain" />
        @else
            <div class="flex flex-col items-center gap-2 text-zinc-400 dark:text-zinc-500">
                <flux:icon :name="$media->kind->icon()" variant="outline" class="size-12" />
                <span class="text-xs font-semibold tracking-wide text-zinc-500 dark:text-zinc-400">{{ $media->extension() }}</span>
            </div>
        @endif
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

            <div x-data="{ locale: @js($currentLocale) }" class="space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <flux:label>{{ __('media.details.alt') }}</flux:label>
                    @if (count($locales) > 1)
                        <div role="tablist" aria-label="{{ __('media.details.alt_language') }}" class="flex rounded-md bg-zinc-100 p-0.5 dark:bg-white/10">
                            @foreach ($locales as $locale => $language)
                                <button
                                    type="button"
                                    role="tab"
                                    wire:key="alt-tab-{{ $locale }}"
                                    title="{{ $language }}"
                                    x-on:click="locale = @js($locale)"
                                    x-bind:aria-selected="locale === @js($locale) ? 'true' : 'false'"
                                    x-bind:class="locale === @js($locale) ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 dark:text-zinc-400'"
                                    class="h-6 rounded px-2.5 text-xs font-medium"
                                >{{ $locale === 'uk' ? 'UA' : Str::upper($locale) }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @foreach ($locales as $locale => $language)
                    <div x-show="locale === @js($locale)" wire:key="alt-field-{{ $locale }}">
                        <flux:textarea
                            wire:model="details.alt.{{ $locale }}"
                            rows="2"
                            :aria-label="__('media.details.alt').' ('.$language.')'"
                            :disabled="! $canEdit"
                        />
                        <flux:error name="details.alt.{{ $locale }}" />
                    </div>
                @endforeach

                <flux:text size="sm">{{ __('media.details.alt_hint') }}</flux:text>
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

            {{-- The clipboard API needs HTTPS; without it the link is selected in the field and a toast says why. --}}
            <div
                class="flex gap-2"
                x-data="{
                    url: @js($media->url()),
                    copy() {
                        if (! navigator.clipboard?.writeText) {
                            return this.selectInstead();
                        }

                        navigator.clipboard.writeText(this.url).then(
                            () => this.$flux.toast({ text: @js(__('media.details.copied')), variant: 'success' }),
                            () => this.selectInstead(),
                        );
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
