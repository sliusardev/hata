{{--
    Folder navigation of the media library.
    Expects: $folder (null, 'none' or a folder id), $folders (rows of folder + depth), $totals (all, none),
    $expandId (a folder to show expanded, set after a subfolder was created in it),
    $canManageFolders (shows "+", makes the folders draggable and "No folder", "All files" and the folders drop targets).
    Alpine keeps the collapse state (localStorage), the folder search and the drag state; every row is rendered, x-show hides it.
--}}
@php
    $itemClasses = 'flex h-9 w-full items-center gap-2 rounded-lg px-2.5 text-start text-sm text-zinc-700 hover:bg-zinc-800/5 dark:text-zinc-300 dark:hover:bg-white/10';
    $rowClasses = str_replace('gap-2', 'gap-1', $itemClasses);
    $currentClasses = 'bg-zinc-800/5 font-medium text-zinc-900 dark:bg-white/10 dark:text-white';
    $hasChildren = collect($folders)->pluck('folder.parent_id')->filter()->flip();
    $treeData = collect($folders)->map(fn (array $row): array => [
        'id' => $row['folder']->id,
        'parent' => $row['folder']->parent_id,
        'name' => $row['folder']->name,
        'kids' => $hasChildren->has($row['folder']->id),
    ])->all();
@endphp

<nav
    aria-label="{{ __('media.index.folders.label') }}"
    class="shrink-0 space-y-0.5 md:w-56"
    wire:key="media-folder-tree-{{ md5(json_encode($treeData)) }}"
    x-data="{
        rows: @js($treeData),
        byId: {},
        expanded: [],
        temporary: [],
        matchSet: new Set(),
        query: '',
        over: null,
        dragging: null,
        hoverId: null,
        hoverTimer: null,
        init() {
            this.byId = Object.fromEntries(this.rows.map((row) => [row.id, row]));
            try {
                const stored = JSON.parse(localStorage.getItem('media.folders.expanded') ?? '[]');
                this.expanded = Array.isArray(stored) ? stored.map(Number).filter((id) => this.byId[id]?.kids) : [];
            } catch (error) {
                this.expanded = [];
            }
            this.revealCurrent();
            this.expandCreatedIn(@js($expandId));
            this.$watch('$wire.folder', () => this.revealCurrent());
            this.$watch('query', () => this.updateMatches());
        },
        revealCurrent() {
            const current = Number($wire.folder);
            if (! Number.isInteger(current) || ! this.byId[current]) { return }
            this.expanded = [...new Set([...this.expanded, ...this.ancestors(current)])];
        },
        expandCreatedIn(id) {
            if (id === null || ! this.byId[id]?.kids || this.expanded.includes(id)) { return }
            this.expanded = [...this.expanded, id];
            this.persist();
        },
        persist() {
            try { localStorage.setItem('media.folders.expanded', JSON.stringify(this.expanded)) } catch (error) {}
        },
        ancestors(id) {
            const result = [];
            let parent = this.byId[id]?.parent;
            while (parent && this.byId[parent] && result.length < 1000) {
                result.push(parent);
                parent = this.byId[parent].parent;
            }
            return result;
        },
        get searching() { return this.query.trim() !== '' },
        updateMatches() {
            const needle = this.query.trim().toLocaleLowerCase();
            const set = new Set();
            if (needle !== '') {
                this.rows.forEach((row) => {
                    if (row.name.toLocaleLowerCase().includes(needle)) {
                        set.add(row.id);
                        this.ancestors(row.id).forEach((id) => set.add(id));
                    }
                });
            }
            this.matchSet = set;
        },
        isOpen(id) { return this.expanded.includes(id) || this.temporary.includes(id) },
        shown(id) {
            if (this.searching) { return this.matchSet.has(id) }
            return this.ancestors(id).every((ancestor) => this.isOpen(ancestor));
        },
        toggle(id) {
            this.expanded = this.expanded.includes(id) ? this.expanded.filter((item) => item !== id) : [...this.expanded, id];
            this.persist();
        },
        @if ($canManageFolders)
        accepts(event, target) {
            const kinds = window.mediaFolderDrag.dragKinds(event);
            if (kinds.folder) {
                return target !== 'none' && this.dragging !== target && ! (this.ancestors(target).includes(this.dragging));
            }
            return kinds.files && target !== 'all';
        },
        enter(event, target) {
            if (! this.accepts(event, target)) { return }
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            this.over = target;
            if (this.byId[target]?.kids && ! this.isOpen(target)) { this.hoverExpand(target) }
        },
        hoverExpand(target) {
            if (this.hoverId === target) { return }
            clearTimeout(this.hoverTimer);
            this.hoverId = target;
            this.hoverTimer = setTimeout(() => { if (this.hoverId === target && ! this.isOpen(target)) { this.temporary = [...this.temporary, target] } }, 700);
        },
        clearHover() { clearTimeout(this.hoverTimer); this.hoverId = null },
        leave(event) {
            if (window.mediaFolderDrag.leftElement(event)) { this.over = null; this.clearHover() }
        },
        reset() { this.over = null; this.dragging = null; this.clearHover(); this.temporary = [] },
        startDrag(event, id) {
            window.mediaFolderDrag.startFolderDrag(event, id);
            this.dragging = id;
        },
        drop(event, target) {
            const { folderId, ids } = window.mediaFolderDrag.readDrop(event);
            this.reset();
            if (folderId !== null) {
                if (folderId !== target) { $wire.moveFolder(folderId, target === 'all' ? null : target) }
            } else if (ids) {
                $wire.moveTo(ids, target === 'none' ? null : target);
            }
        },
        @endif
    }"
    @if ($canManageFolders) x-on:dragend.window="reset()" @endif
>
    <button
        type="button"
        wire:click="$set('folder', null)"
        @class([$itemClasses, $currentClasses => $folder === null])
        @if ($folder === null) aria-current="page" @endif
        @if ($canManageFolders)
            x-on:dragover="enter($event, 'all')"
            x-on:dragleave="leave($event)"
            x-on:drop.prevent="drop($event, 'all')"
            x-bind:class="over === 'all' && 'ring-2 ring-accent ring-inset'"
        @endif
    >
        <flux:icon name="squares-2x2" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.all') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['all'] }}</span>
    </button>

    <button
        type="button"
        wire:click="$set('folder', 'none')"
        @class([$itemClasses, $currentClasses => $folder === 'none'])
        @if ($folder === 'none') aria-current="page" @endif
        @if ($canManageFolders)
            x-on:dragover="enter($event, 'none')"
            x-on:dragleave="leave($event)"
            x-on:drop.prevent="drop($event, 'none')"
            x-bind:class="over === 'none' && 'ring-2 ring-accent ring-inset'"
        @endif
    >
        <flux:icon name="inbox" variant="outline" class="size-4 shrink-0 text-zinc-400" />
        <span class="flex-1 truncate">{{ __('media.index.folders.none') }}</span>
        <span class="text-xs text-zinc-400 tabular-nums">{{ $totals['none'] }}</span>
    </button>

    @if ($folders !== [] || $canManageFolders)
        <div class="flex h-9 items-center justify-between ps-2.5 pt-3">
            <span class="text-[11px] font-semibold tracking-wider text-zinc-400 uppercase dark:text-zinc-500">{{ __('media.index.folders.heading') }}</span>
            @if ($canManageFolders)
                <flux:button size="xs" variant="ghost" icon="plus" wire:click="openNewFolder" :aria-label="__('media.folders.new')" :tooltip="__('media.folders.new')" data-test="media-new-folder" />
            @endif
        </div>
    @endif

    @if (count($folders) > 15)
        <flux:input size="sm" icon="magnifying-glass" clearable x-model="query" :placeholder="__('media.index.folders.search')" :aria-label="__('media.index.folders.search')" data-test="media-folder-search" />
        <p x-show="searching && matchSet.size === 0" x-cloak class="px-2.5 py-2 text-xs text-zinc-500 dark:text-zinc-400">{{ __('media.index.folders.no_match') }}</p>
    @endif

    @foreach ($folders as $row)
        @php
            $id = $row['folder']->id;
            $isCurrent = $folder === (string) $id;
        @endphp
        <div
            wire:key="folder-{{ $id }}"
            @class(['group/row', $rowClasses, $currentClasses => $isCurrent])
            x-cloak
            style="padding-inline-start: {{ 0.625 + min($row['depth'], 4) * 0.75 }}rem"
            x-show="shown({{ $id }})"
            @if ($canManageFolders)
                x-on:dragover="enter($event, {{ $id }})"
                x-on:dragleave="leave($event)"
                x-on:drop.prevent="drop($event, {{ $id }})"
                x-bind:class="over === {{ $id }} && 'ring-2 ring-accent ring-inset'"
            @endif
        >
            @if ($hasChildren->has($id))
                <button
                    type="button"
                    class="flex size-5 shrink-0 items-center justify-center rounded text-zinc-400 hover:bg-zinc-800/10 dark:hover:bg-white/10"
                    x-on:click.stop="toggle({{ $id }})"
                    x-bind:aria-expanded="isOpen({{ $id }}).toString()"
                    x-bind:aria-label="isOpen({{ $id }}) ? @js(__('media.index.folders.collapse')) : @js(__('media.index.folders.expand'))"
                    data-test="media-folder-toggle-{{ $id }}"
                >
                    <flux:icon name="chevron-right" variant="micro" class="size-3.5 transition-transform rtl:rotate-180" x-bind:class="isOpen({{ $id }}) && 'rotate-90 rtl:-rotate-90'" />
                </button>
            @else
                <span class="size-5 shrink-0" aria-hidden="true"></span>
            @endif
            <div
                role="button"
                tabindex="0"
                class="flex h-full min-w-0 flex-1 cursor-pointer items-center gap-2 text-start"
                title="{{ $row['folder']->name }}"
                wire:click="$set('folder', '{{ $id }}')"
                x-on:keydown.enter.prevent="$el.click()"
                x-on:keydown.space.prevent="$el.click()"
                @if ($isCurrent) aria-current="page" @endif
                @if ($canManageFolders)
                    draggable="true"
                    x-on:dragstart="startDrag($event, {{ $id }})"
                @endif
                data-test="media-folder-{{ $id }}"
            >
                <flux:icon name="folder" variant="outline" class="size-4 shrink-0 text-zinc-400" />
                <span class="flex-1 truncate" title="{{ $row['folder']->name }}">{{ $row['folder']->name }}</span>
                <span class="text-xs text-zinc-400 tabular-nums">{{ $row['folder']->media_count }}</span>
            </div>
            @if ($canManageFolders)
                @include('hata::partials.admin.media.folder-menu', [
                    'folderId' => $id,
                    'testId' => 'media-folder-actions-'.$id,
                    'stop' => true,
                    'class' => 'opacity-0 group-focus-within/row:opacity-100 group-hover/row:opacity-100 pointer-coarse:opacity-100 [&:has([aria-expanded=true])]:opacity-100',
                ])
            @endif
        </div>
    @endforeach

    @if ($canManageFolders && $folders !== [])
        <flux:text size="sm" class="px-2.5 pt-3 text-xs">{{ __('media.folders.move_hint') }}</flux:text>
    @endif
</nav>
