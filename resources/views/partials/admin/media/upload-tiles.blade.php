{{--
    Tiles of the files still in the upload queue (waiting, uploading or failed), shown before the grid.
    Rendered by Alpine from the page's mediaUploader state, so Livewire leaves it alone. Uploaded files
    disappear from here and come back as regular tiles from the server.
--}}
<div wire:ignore>
    <div x-cloak x-show="pendingTiles.length > 0" class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5" data-test="media-upload-tiles">
        <template x-for="item in pendingTiles" :key="item.id">
            <div class="min-w-0">
                <div
                    class="relative aspect-square overflow-hidden rounded-lg bg-zinc-100 ring-1 ring-black/5 ring-inset dark:bg-white/5 dark:ring-white/10"
                    x-bind:class="item.status === 'failed' && 'bg-red-50! ring-red-200! dark:bg-red-400/10! dark:ring-red-400/30!'"
                >
                    <template x-if="item.preview && item.status !== 'failed'">
                        <img x-bind:src="item.preview" alt="" class="size-full object-cover opacity-60" />
                    </template>
                    <template x-if="! item.preview && item.status !== 'failed'">
                        <div class="flex size-full items-center justify-center text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400" x-text="item.extension"></div>
                    </template>

                    <template x-if="item.status !== 'failed'">
                        <div class="absolute inset-x-2.5 bottom-2.5 flex flex-col gap-1.5">
                            <span class="self-start rounded-md bg-white/90 px-1.5 py-0.5 text-xs font-medium text-zinc-800" x-text="statusText(item)"></span>
                            <div class="h-1.5 overflow-hidden rounded-full bg-white/75">
                                <div class="h-full rounded-full bg-zinc-800 transition-all" x-bind:style="{ width: (item.status === 'compressing' ? 100 : item.percent) + '%' }"></div>
                            </div>
                        </div>
                    </template>

                    <template x-if="item.status === 'failed'">
                        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-3 text-center">
                            <flux:icon name="exclamation-circle" variant="outline" class="size-8 text-red-700 dark:text-red-400" />
                            <span class="text-xs leading-snug text-red-800 dark:text-red-300" x-text="item.error"></span>
                        </div>
                    </template>
                </div>
                <div class="mt-2 min-w-0 px-0.5">
                    <div class="truncate text-sm font-medium text-zinc-800 dark:text-white" x-text="item.name"></div>
                    <div
                        class="text-xs"
                        x-bind:class="item.status === 'failed' ? 'text-red-700 dark:text-red-400' : 'text-zinc-500 dark:text-zinc-400'"
                        x-text="item.status === 'failed' ? @js(__('media.upload.not_uploaded')) : statusText(item)"
                    ></div>
                </div>
            </div>
        </template>
    </div>
</div>
