{{--
    Drop overlay (screen 2) and upload queue panel (screen 3) of the media library.
    Rendered by Alpine from the page's mediaUploader state (resources/js/media-uploader.js), so Livewire leaves it alone.
    Expects: $imageMb, $documentMb (upload limits for the hint).
--}}
<div wire:ignore>
    <div
        x-cloak
        x-show="dragging"
        x-transition.opacity
        class="pointer-events-none fixed inset-4 z-50 flex flex-col items-center justify-center gap-4 rounded-2xl border-2 border-dashed border-zinc-500 bg-zinc-50/90 p-6 text-center dark:border-zinc-400 dark:bg-zinc-900/90"
        data-test="media-drop-overlay"
    >
        <div class="flex size-18 items-center justify-center rounded-2xl bg-zinc-800 text-white dark:bg-white dark:text-zinc-800">
            <flux:icon name="arrow-up-tray" variant="outline" class="size-9" />
        </div>
        <div class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('hata::media.upload.drop_title') }}</div>
        <div class="max-w-md text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
            {{ __('hata::media.upload.drop_hint', ['image' => $imageMb, 'document' => $documentMb]) }}
        </div>
    </div>

    <section
        x-cloak
        x-show="queue.length > 0"
        aria-label="{{ __('hata::media.upload.queue') }}"
        class="fixed end-4 bottom-4 z-40 w-[22.5rem] max-w-[calc(100%-2rem)] overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-white/10 dark:bg-zinc-800"
        data-test="media-upload-queue"
    >
        <div class="flex items-center justify-between gap-2 border-b border-zinc-200 py-2 ps-4 pe-2 dark:border-white/10">
            <span class="text-sm font-semibold text-zinc-900 dark:text-white" x-text="queueTitle" aria-live="polite"></span>
            <div class="flex items-center">
                <flux:button
                    size="sm"
                    variant="ghost"
                    square
                    x-on:click="collapsed = ! collapsed"
                    x-bind:aria-expanded="collapsed ? 'false' : 'true'"
                    x-bind:aria-label="collapsed ? @js(__('hata::media.upload.expand')) : @js(__('hata::media.upload.collapse'))"
                >
                    <flux:icon name="chevron-down" variant="micro" class="size-4 transition" x-bind:class="collapsed && 'rotate-180'" />
                </flux:button>
                <flux:button size="sm" variant="ghost" square x-show="! busy" x-on:click="clear()" :aria-label="__('hata::media.upload.close')">
                    <flux:icon name="x-mark" variant="micro" class="size-4" />
                </flux:button>
            </div>
        </div>

        <ul x-show="! collapsed" class="max-h-80 overflow-y-auto py-1.5">
            <template x-for="item in queue" :key="item.id">
                <li class="flex items-center gap-3 px-4 py-2">
                    <div class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-md bg-zinc-100 dark:bg-white/5">
                        <template x-if="item.preview">
                            <img x-bind:src="item.preview" alt="" class="size-full object-cover" />
                        </template>
                        <template x-if="! item.preview">
                            <span class="text-[10px] font-semibold text-zinc-500 uppercase dark:text-zinc-400" x-text="item.extension"></span>
                        </template>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium text-zinc-800 dark:text-white" x-text="item.name"></div>
                        <div
                            class="text-xs"
                            x-bind:class="{
                                'text-green-700 dark:text-green-400': item.status === 'done',
                                'text-red-700 dark:text-red-400': item.status === 'failed',
                                'text-zinc-500 dark:text-zinc-400': ! ['done', 'failed'].includes(item.status),
                            }"
                            x-text="statusText(item)"
                        ></div>
                    </div>
                    <template x-if="item.status === 'done'">
                        <flux:icon name="check" variant="mini" class="size-5 shrink-0 text-green-700 dark:text-green-400" />
                    </template>
                    <template x-if="item.status === 'failed'">
                        <flux:button size="sm" x-on:click="remove(item)">{{ __('hata::media.upload.remove') }}</flux:button>
                    </template>
                </li>
            </template>
        </ul>
    </section>
</div>
