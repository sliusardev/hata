<?php

use Hata\Actions\Maintenance\DeleteLogFile;
use Hata\Actions\Maintenance\RunMaintenanceAction;
use Hata\Exceptions\MaintenanceException;
use Hata\Http\Controllers\Admin\MaintenanceRunController;
use Hata\Enums\MaintenanceAction;
use Hata\Models\MaintenanceRun;
use Hata\Support\Maintenance\LogFiles;
use Hata\Support\Maintenance\PendingMigrations;
use Hata\Support\Maintenance\SeederCatalog;
use Flux\Flux;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('maintenance.title')] class extends Component {
    use WithPagination;

    /**
     * Runs per page of the journal.
     */
    public const int PER_PAGE = 15;

    /**
     * The tabs and their icons. A new area of the page (e.g. database tools) is a new entry here and a block
     * in the template; each tab loads only its own data.
     *
     * @var array<string, string>
     */
    public const array TABS = ['actions' => 'wrench-screwdriver', 'journal' => 'clipboard-document-list', 'logs' => 'document-text'];

    #[Url(except: 'actions')]
    public string $tab = 'actions';

    /**
     * The seeder picked in the select, a name from SeederCatalog::names().
     */
    public string $seeder = '';

    /**
     * The action the open confirmation is about, a MaintenanceAction value.
     */
    #[Locked]
    public ?string $confirming = null;

    public bool $showConfirmModal = false;

    /**
     * The seeder the open confirmation named; the dialog posts this, not whatever the select holds by then.
     */
    #[Locked]
    public ?string $confirmedSeeder = null;

    /**
     * The run made just before this page load (from the session), shown under the actions.
     */
    #[Locked]
    public ?int $lastRunId = null;

    /**
     * The journal entry whose output is open.
     */
    #[Locked]
    public ?int $viewingRunId = null;

    public bool $showOutputModal = false;

    /**
     * The log file open in the viewer, a name LogFiles lists; empty shows the list of files.
     */
    #[Url(except: '')]
    public string $file = '';

    /**
     * The log file the open delete confirmation is about.
     */
    #[Locked]
    public ?string $deletingLog = null;

    public bool $showDeleteLogModal = false;

    public function mount(): void
    {
        $this->authorize('maintenance.run');

        $this->keepKnownTab();
        $this->keepKnownFile();
        $this->pickDefaultSeeder();

        $lastRun = session(MaintenanceRunController::LAST_RUN_KEY);
        $this->lastRunId = is_int($lastRun) ? $lastRun : null;
    }

    public function updatedTab(): void
    {
        $this->keepKnownTab();
        $this->pickDefaultSeeder();
        $this->resetPage();
        $this->file = '';
    }

    public function updatedFile(): void
    {
        $this->keepKnownFile();
    }

    /**
     * A file is open only on the logs tab, and only one the logs folder lists.
     */
    private function keepKnownFile(): void
    {
        if ($this->file === '') {
            return;
        }

        if ($this->tab !== 'logs') {
            $this->file = '';

            return;
        }

        if ($this->openedLog === null) {
            $this->file = '';
            Flux::toast(variant: 'danger', text: __('maintenance.logs.errors.missing'));
        }
    }

    /**
     * The select starts on the first seeder; looked up only on the tab that shows it.
     */
    private function pickDefaultSeeder(): void
    {
        if ($this->tab === 'actions' && $this->seeder === '') {
            $this->seeder = $this->seeders[0] ?? '';
        }
    }

    private function keepKnownTab(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'actions';
        }
    }

    /**
     * Ask before running an action. The dialog's button then posts the action to MaintenanceRunController: the
     * commands run in a plain request, never in a Livewire one (see the controller).
     */
    public function confirm(string $action): void
    {
        $this->authorize('maintenance.run');

        $action = MaintenanceAction::tryFrom($action);

        if ($action === null) {
            return;
        }

        if ($action === MaintenanceAction::Migrate && $this->pendingMigrations === []) {
            Flux::toast(variant: 'danger', text: __('maintenance.errors.nothing_pending'));

            return;
        }

        if ($action === MaintenanceAction::Seed && ! in_array($this->seeder, $this->seeders, true)) {
            Flux::toast(variant: 'danger', text: __('maintenance.errors.unknown_seeder'));

            return;
        }

        $this->confirming = $action->value;
        $this->confirmedSeeder = $action === MaintenanceAction::Seed ? $this->seeder : null;
        $this->showConfirmModal = true;
    }

    /**
     * Forget what a closed confirmation was about.
     */
    public function cancelConfirm(): void
    {
        $this->showConfirmModal = false;
        $this->confirming = null;
        $this->confirmedSeeder = null;
    }

    public function showOutput(int $id): void
    {
        $this->authorize('maintenance.run');

        $this->viewingRunId = $id;
        unset($this->viewingRun);
        $this->showOutputModal = $this->viewingRun !== null;
    }

    /**
     * Open a log file in the viewer. The key comes from LogFiles, so a file name never travels in a script.
     */
    public function openLog(string $key): void
    {
        $this->authorize('maintenance.run');

        $file = app(LogFiles::class)->findByKey($key);

        if ($file === null) {
            $this->logFileGone();

            return;
        }

        $this->file = $file['name'];
        unset($this->openedLog);
    }

    public function closeLog(): void
    {
        $this->file = '';
        unset($this->openedLog, $this->logFiles);
    }

    public function confirmDeleteLog(string $key): void
    {
        $this->authorize('maintenance.run');

        $file = app(LogFiles::class)->findByKey($key);

        if ($file === null) {
            $this->logFileGone();

            return;
        }

        $this->deletingLog = $file['name'];
        $this->showDeleteLogModal = true;
    }

    public function deleteLog(): void
    {
        $this->authorize('maintenance.run');

        $name = $this->deletingLog;
        $this->deletingLog = null;
        $this->showDeleteLogModal = false;

        if ($name === null) {
            return;
        }

        try {
            app(DeleteLogFile::class)->handle(auth()->user(), $name);
            Flux::toast(variant: 'success', text: __('maintenance.logs.messages.deleted', ['name' => $name]));
        } catch (MaintenanceException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }

        $this->closeMissingLog();
    }

    private function logFileGone(): void
    {
        Flux::toast(variant: 'danger', text: __('maintenance.logs.errors.missing'));

        $this->closeMissingLog();
    }

    /**
     * Back to the list when the open file is no longer in the folder (deleted here or elsewhere). A file that
     * could not be deleted stays open.
     */
    private function closeMissingLog(): void
    {
        unset($this->openedLog, $this->logFiles);

        if ($this->file !== '' && $this->openedLog === null) {
            $this->file = '';
        }
    }

    /**
     * The files of the logs folder, newest first.
     *
     * @return list<array{key: string, name: string, size: int, modified: int}>
     */
    #[Computed]
    public function logFiles(): array
    {
        return app(LogFiles::class)->all();
    }

    /**
     * The file open in the viewer. Its content is not loaded here: the viewer fetches it in chunks.
     *
     * @return array{key: string, name: string, size: int, modified: int}|null
     */
    #[Computed]
    public function openedLog(): ?array
    {
        return $this->file === '' ? null : app(LogFiles::class)->find($this->file);
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function pendingMigrations(): array
    {
        return app(PendingMigrations::class)->names();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function seeders(): array
    {
        return app(SeederCatalog::class)->names();
    }

    /**
     * The journal, newest first, without the outputs. Empty while the journal table does not exist yet (this
     * feature's own migration is still pending).
     *
     * @return LengthAwarePaginator<int, MaintenanceRun>
     */
    #[Computed]
    public function runs(): LengthAwarePaginator
    {
        try {
            return MaintenanceRun::query()->select(MaintenanceRun::LIST_COLUMNS)->orderByDesc('id')->paginate(self::PER_PAGE);
        } catch (QueryException) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE);
        }
    }

    #[Computed]
    public function lastRun(): ?MaintenanceRun
    {
        try {
            return $this->lastRunId === null ? null : MaintenanceRun::query()->find($this->lastRunId);
        } catch (QueryException) {
            return null;
        }
    }

    #[Computed]
    public function viewingRun(): ?MaintenanceRun
    {
        return $this->viewingRunId === null ? null : MaintenanceRun::query()->find($this->viewingRunId);
    }

    /**
     * The text of the open confirmation.
     */
    #[Computed]
    public function confirmText(): string
    {
        return match (MaintenanceAction::tryFrom((string) $this->confirming)) {
            MaintenanceAction::Migrate => trans_choice('maintenance.actions.migrate.confirm', count($this->pendingMigrations), ['count' => count($this->pendingMigrations)]),
            MaintenanceAction::Seed => __('maintenance.actions.seed.confirm', ['seeder' => (string) $this->confirmedSeeder]),
            MaintenanceAction::ClearCaches => __('maintenance.actions.clear_caches.confirm'),
            MaintenanceAction::RebuildCaches => __('maintenance.actions.rebuild_caches.confirm'),
            null => '',
        };
    }
}; ?>

@php
    $pending = $tab === 'actions' ? $this->pendingMigrations : [];
    $seeders = $tab === 'actions' ? $this->seeders : [];
    $lastRun = $tab === 'actions' ? $this->lastRun : null;
    $openedLog = $tab === 'logs' ? $this->openedLog : null;
    $logFiles = $tab === 'logs' && $openedLog === null ? $this->logFiles : [];
    $logDate = fn (int $timestamp): string => Illuminate\Support\Facades\Date::createFromTimestamp($timestamp)->tz(config('admin.timezone'))->isoFormat('LL, LT');
    $cardClasses = 'flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-zinc-900';
    $iconClasses = 'flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200';
    $outputClasses = 'max-h-96 overflow-auto rounded-lg bg-zinc-900 p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100 dark:bg-black/40';
@endphp

<section class="w-full space-y-8 {{ $openedLog ? 'max-w-7xl' : 'max-w-5xl' }}">
    <div>
        <flux:heading size="xl" level="1">{{ __('maintenance.title') }}</flux:heading>
        <flux:subheading>{{ __('maintenance.description') }}</flux:subheading>
    </div>

    <flux:navbar scrollable class="-mx-3 border-b border-zinc-200 px-0 dark:border-zinc-700" data-test="maintenance-tabs">
        @foreach ($this::TABS as $name => $icon)
            <flux:navbar.item
                :icon="$icon"
                :current="$tab === $name"
                wire:click="$set('tab', '{{ $name }}')"
                wire:key="tab-{{ $name }}"
                class="cursor-pointer"
                data-test="maintenance-tab-{{ $name }}"
            >
                {{ __("maintenance.tabs.{$name}") }}
            </flux:navbar.item>
        @endforeach
    </flux:navbar>

    @if ($tab === 'actions')
    <div class="grid gap-5 lg:grid-cols-2">
        {{-- Migrations --}}
        <div class="{{ $cardClasses }}" data-test="maintenance-migrate">
            <div class="flex items-start gap-4">
                <div class="{{ $iconClasses }}"><flux:icon name="circle-stack" variant="outline" class="size-5" /></div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:heading level="2">{{ __('maintenance.actions.migrate.title') }}</flux:heading>
                        @if ($pending !== [])
                            <flux:badge size="sm" color="amber" data-test="maintenance-pending-count">{{ count($pending) }}</flux:badge>
                        @endif
                    </div>
                    <flux:text size="sm" class="mt-1">{{ __('maintenance.actions.migrate.description') }}</flux:text>
                </div>
            </div>

            @if ($pending === [])
                <div class="flex items-center gap-2 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 dark:bg-green-400/10 dark:text-green-300" data-test="maintenance-nothing-pending">
                    <flux:icon name="check-circle" variant="mini" class="size-5 shrink-0" />
                    {{ __('maintenance.actions.migrate.none') }}
                </div>
            @else
                <div>
                    <flux:text size="sm" class="mb-2 font-medium">{{ __('maintenance.actions.migrate.pending') }}</flux:text>
                    <ul class="max-h-40 space-y-1 overflow-y-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700 dark:bg-white/5 dark:text-zinc-300" data-test="maintenance-pending-list">
                        @foreach ($pending as $migration)
                            <li class="truncate" wire:key="pending-{{ $migration }}" title="{{ $migration }}">{{ $migration }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-auto flex justify-end">
                    <flux:button variant="primary" icon="play" wire:click="confirm('migrate')" data-test="maintenance-run-migrate">
                        {{ __('maintenance.actions.migrate.button') }}
                    </flux:button>
                </div>
            @endif
        </div>

        {{-- Seeder --}}
        <div class="{{ $cardClasses }}" data-test="maintenance-seed">
            <div class="flex items-start gap-4">
                <div class="{{ $iconClasses }}"><flux:icon name="table-cells" variant="outline" class="size-5" /></div>
                <div class="min-w-0 flex-1">
                    <flux:heading level="2">{{ __('maintenance.actions.seed.title') }}</flux:heading>
                    <flux:text size="sm" class="mt-1">{{ __('maintenance.actions.seed.description') }}</flux:text>
                </div>
            </div>

            @if ($seeders === [])
                <flux:text size="sm" variant="subtle">{{ __('maintenance.actions.seed.none') }}</flux:text>
            @else
                <div class="mt-auto flex flex-wrap items-end gap-3">
                    <div class="min-w-48 flex-1">
                        <flux:select wire:model="seeder" :label="__('maintenance.actions.seed.select')" data-test="maintenance-seeder">
                            @foreach ($seeders as $name)
                                <flux:select.option :value="$name" wire:key="seeder-{{ $name }}">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:button variant="primary" icon="play" wire:click="confirm('seed')" data-test="maintenance-run-seed">
                        {{ __('maintenance.actions.seed.button') }}
                    </flux:button>
                </div>
            @endif
        </div>

        {{-- Caches --}}
        @foreach ([['clear_caches', 'trash'], ['rebuild_caches', 'bolt']] as [$key, $icon])
            <div class="{{ $cardClasses }}" wire:key="card-{{ $key }}" data-test="maintenance-{{ $key }}">
                <div class="flex items-start gap-4">
                    <div class="{{ $iconClasses }}"><flux:icon :name="$icon" variant="outline" class="size-5" /></div>
                    <div class="min-w-0 flex-1">
                        <flux:heading level="2">{{ __("maintenance.actions.{$key}.title") }}</flux:heading>
                        <flux:text size="sm" class="mt-1">{{ __("maintenance.actions.{$key}.description") }}</flux:text>
                    </div>
                </div>

                <div class="mt-auto flex justify-end">
                    <flux:button icon="play" wire:click="confirm('{{ $key }}')" data-test="maintenance-run-{{ $key }}">
                        {{ __("maintenance.actions.{$key}.button") }}
                    </flux:button>
                </div>
            </div>
        @endforeach
    </div>

    @if ($lastRun)
        @php $status = $lastRun->shownStatus(); @endphp
        <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-zinc-900" data-test="maintenance-last-result">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading level="2">{{ __('maintenance.result.heading') }}</flux:heading>
                <flux:badge size="sm" :color="$status->color()" data-test="maintenance-last-status">{{ $status->label() }}</flux:badge>
                <flux:text size="sm">{{ $lastRun->title() }}</flux:text>
                @if ($lastRun->seconds() !== null)
                    <flux:text size="sm" variant="subtle">· {{ __('maintenance.result.duration', ['seconds' => $lastRun->seconds()]) }}</flux:text>
                @endif
            </div>

            @if ($lastRun->output_truncated)
                <flux:text size="sm" variant="subtle">{{ __('maintenance.result.truncated') }}</flux:text>
            @endif

            <pre class="{{ $outputClasses }}" data-test="maintenance-last-output">{{ $lastRun->output !== null && $lastRun->output !== '' ? $lastRun->output : __('maintenance.result.no_output') }}</pre>

            <flux:link href="#" wire:click.prevent="$set('tab', 'journal')" class="text-sm">{{ __('maintenance.result.all_runs') }}</flux:link>
        </div>
    @endif

    @endif

    @if ($tab === 'journal')
    <div class="space-y-4" data-test="maintenance-journal-tab">
        <flux:text>{{ __('maintenance.journal.description', ['count' => RunMaintenanceAction::KEEP]) }}</flux:text>

        @if ($this->runs->isEmpty())
            <x-admin.empty-state
                icon="clipboard-document-list"
                :heading="__('maintenance.journal.empty_title')"
                :description="__('maintenance.journal.empty_description')"
            />
        @else
            <flux:table :paginate="$this->runs" data-test="maintenance-journal">
                <flux:table.columns>
                    <flux:table.column>{{ __('maintenance.journal.when') }}</flux:table.column>
                    <flux:table.column class="max-md:hidden">{{ __('maintenance.journal.who') }}</flux:table.column>
                    <flux:table.column>{{ __('maintenance.journal.action') }}</flux:table.column>
                    <flux:table.column>{{ __('maintenance.journal.status') }}</flux:table.column>
                    <flux:table.column align="end" class="max-sm:hidden">{{ __('maintenance.journal.duration') }}</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">{{ __('maintenance.journal.actions') }}</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->runs as $run)
                        @php $runStatus = $run->shownStatus(); @endphp
                        <flux:table.row :key="$run->id">
                            <flux:table.cell class="whitespace-nowrap">{{ $run->started_at->tz(config('admin.timezone'))->isoFormat('LL, LT') }}</flux:table.cell>
                            <flux:table.cell class="max-md:hidden">{{ $run->user_name }}</flux:table.cell>
                            <flux:table.cell>
                                <button type="button" wire:click="showOutput({{ $run->id }})" class="max-w-64 truncate text-start font-medium text-zinc-800 hover:underline dark:text-white">{{ $run->title() }}</button>
                            </flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$runStatus->color()">{{ $runStatus->label() }}</flux:badge></flux:table.cell>
                            <flux:table.cell align="end" class="max-sm:hidden">
                                {{ $run->seconds() === null ? '—' : __('maintenance.result.duration', ['seconds' => $run->seconds()]) }}
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <x-admin.row-actions data-test="maintenance-run-actions-{{ $run->id }}">
                                    <flux:menu.item icon="command-line" wire:click="showOutput({{ $run->id }})">{{ __('maintenance.journal.view_output') }}</flux:menu.item>
                                </x-admin.row-actions>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
    @endif

    @if ($tab === 'logs' && $openedLog === null)
    <div class="space-y-4" data-test="maintenance-logs-tab">
        <flux:text>{{ __('maintenance.logs.description') }}</flux:text>

        @if ($logFiles === [])
            <x-admin.empty-state
                icon="document-text"
                :heading="__('maintenance.logs.empty_title')"
                :description="__('maintenance.logs.empty_description')"
            />
        @else
            <flux:table data-test="maintenance-logs">
                <flux:table.columns>
                    <flux:table.column>{{ __('maintenance.logs.file') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('maintenance.logs.size') }}</flux:table.column>
                    <flux:table.column class="max-sm:hidden">{{ __('maintenance.logs.changed') }}</flux:table.column>
                    <flux:table.column align="end"><span class="sr-only">{{ __('maintenance.journal.actions') }}</span></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($logFiles as $logFile)
                        <flux:table.row :key="$logFile['key']">
                            <flux:table.cell>
                                <button type="button" wire:click="openLog('{{ $logFile['key'] }}')" class="flex max-w-80 items-center gap-2 text-start font-medium text-zinc-800 hover:underline dark:text-white">
                                    <flux:icon name="document-text" variant="mini" class="size-4 shrink-0 text-zinc-400" />
                                    <span class="truncate">{{ $logFile['name'] }}</span>
                                </button>
                            </flux:table.cell>
                            <flux:table.cell align="end" class="whitespace-nowrap tabular-nums">{{ Hata\Models\Media::formatBytes($logFile['size']) }}</flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap max-sm:hidden">{{ $logDate($logFile['modified']) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <x-admin.row-actions data-test="maintenance-log-actions-{{ $logFile['key'] }}">
                                    <flux:menu.item icon="eye" wire:click="openLog('{{ $logFile['key'] }}')">{{ __('maintenance.logs.open') }}</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="confirmDeleteLog('{{ $logFile['key'] }}')">{{ __('common.actions.delete') }}</flux:menu.item>
                                </x-admin.row-actions>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
    @endif

    @if ($openedLog)
    <div class="space-y-4" data-test="maintenance-log-viewer">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="closeLog" data-test="maintenance-log-back">
                {{ __('maintenance.logs.back') }}
            </flux:button>

            <div class="min-w-0 flex-1">
                <flux:heading level="2" class="truncate">{{ $openedLog['name'] }}</flux:heading>
                <flux:text size="sm">{{ Hata\Models\Media::formatBytes($openedLog['size']) }} · {{ $logDate($openedLog['modified']) }}</flux:text>
            </div>

            <flux:button size="sm" variant="danger" icon="trash" wire:click="confirmDeleteLog('{{ $openedLog['key'] }}')" data-test="maintenance-log-delete">
                {{ __('common.actions.delete') }}
            </flux:button>
        </div>

        {{-- The content is fetched in chunks from the chunk route and kept out of Livewire's renders. --}}
        <div
            wire:ignore
            wire:key="log-viewer-{{ $openedLog['key'] }}"
            data-url="{{ route('admin.maintenance.logs.chunk', $openedLog['key']) }}"
            class="overflow-hidden rounded-xl border border-zinc-200 bg-zinc-900 dark:border-white/10 dark:bg-black/40"
            x-data="{
                before: null,
                start: 0,
                remaining: '',
                loading: false,
                failed: false,
                empty: false,
                async load() {
                    if (this.loading) {
                        return;
                    }

                    this.loading = true;
                    this.failed = false;

                    try {
                        const url = new URL(this.$root.dataset.url, window.location.origin);

                        if (this.before !== null) {
                            url.searchParams.set('before', this.before);
                        }

                        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

                        if (! response.ok) {
                            throw new Error(response.status);
                        }

                        const chunk = await response.json();
                        const box = this.$refs.box;
                        const first = this.before === null;
                        const fromBottom = box.scrollHeight - box.scrollTop;

                        this.$refs.text.insertAdjacentHTML('afterbegin', chunk.html);
                        this.before = chunk.start;
                        this.start = chunk.start;
                        this.remaining = chunk.remaining ?? '';
                        this.empty = first && chunk.size === 0;

                        this.$nextTick(() => {
                            box.scrollTop = first ? box.scrollHeight : box.scrollHeight - fromBottom;
                        });
                    } catch (error) {
                        this.failed = true;
                    } finally {
                        this.loading = false;
                    }
                },
            }"
            x-init="load()"
        >
            <div x-ref="box" class="h-[70vh] overflow-auto p-4 font-mono text-xs leading-relaxed text-zinc-100">
                <div class="mb-3 flex justify-center" x-show="start > 0" x-cloak>
                    <button
                        type="button"
                        class="rounded-md bg-white/10 px-3 py-1.5 font-sans text-xs font-medium text-zinc-100 hover:bg-white/20 disabled:opacity-50"
                        x-on:click="load()"
                        x-bind:disabled="loading"
                        data-test="maintenance-log-earlier"
                    >
                        {{ __('maintenance.logs.load_earlier') }} · <span x-text="remaining"></span>
                    </button>
                </div>

                <p class="font-sans text-sm text-zinc-400" x-show="loading && before === null" role="status">{{ __('maintenance.logs.loading') }}</p>
                <p class="font-sans text-sm text-zinc-400" x-show="empty" x-cloak>{{ __('maintenance.logs.empty_file') }}</p>
                <div class="flex flex-wrap items-center gap-3 font-sans text-sm text-red-300" x-show="failed" x-cloak role="alert">
                    <span>{{ __('maintenance.logs.failed') }}</span>
                    <button type="button" class="rounded-md bg-white/10 px-3 py-1.5 text-xs font-medium text-zinc-100 hover:bg-white/20" x-on:click="load()" data-test="maintenance-log-retry">
                        {{ __('maintenance.logs.retry') }}
                    </button>
                </div>

                <div x-ref="text" class="log-text"></div>
            </div>
        </div>
    </div>
    @endif

    <x-confirm-modal
        wire:model.self="showDeleteLogModal"
        :heading="__('maintenance.logs.delete.title')"
        :confirm-label="__('maintenance.logs.delete.submit')"
        confirm-action="deleteLog"
    >
        {{ __('maintenance.logs.delete.confirm', ['name' => (string) $deletingLog]) }}
    </x-confirm-modal>

    {{-- Not <x-confirm-modal>: its button calls a Livewire action, and these commands must run in a plain request. --}}
    <flux:modal wire:model.self="showConfirmModal" wire:close="cancelConfirm" class="max-w-md md:min-w-md">
        <form
            method="POST"
            action="{{ route('admin.maintenance.run') }}"
            class="space-y-6"
            x-data="{ running: false }"
            x-on:submit="running = true"
            data-test="maintenance-confirm-form"
        >
            @csrf
            <input type="hidden" name="action" value="{{ $confirming }}" />
            <input type="hidden" name="seeder" value="{{ $confirmedSeeder }}" />

            <div class="space-y-2">
                <flux:heading size="lg">{{ __('maintenance.confirm.heading') }}</flux:heading>
                <flux:text>{{ $this->confirmText }}</flux:text>
            </div>

            @if ($confirming === 'rebuild_caches' && app()->isLocal())
                <flux:callout icon="exclamation-triangle" color="amber" data-test="maintenance-local-warning">
                    <flux:callout.text>{{ __('maintenance.actions.rebuild_caches.confirm_local') }}</flux:callout.text>
                </flux:callout>
            @endif

            <div class="flex items-center justify-end gap-3">
                <flux:text size="sm" x-show="running" x-cloak role="status">{{ __('maintenance.confirm.running') }}</flux:text>
                <flux:modal.close>
                    <flux:button variant="outline" x-bind:disabled="running">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger" x-bind:disabled="running" data-test="confirm-modal-button">
                    {{ __('maintenance.confirm.button') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model.self="showOutputModal" class="w-full max-w-3xl">
        @if ($viewing = $this->viewingRun)
            @php $viewingStatus = $viewing->shownStatus(); @endphp
            <div class="space-y-4" data-test="maintenance-output-modal">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ $viewing->title() }}</flux:heading>
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$viewingStatus->color()">{{ $viewingStatus->label() }}</flux:badge>
                        <flux:text size="sm">{{ $viewing->user_name }} · {{ $viewing->started_at->tz(config('admin.timezone'))->isoFormat('LL, LT') }}</flux:text>
                    </div>
                </div>

                @if ($viewing->output_truncated)
                    <flux:text size="sm" variant="subtle">{{ __('maintenance.result.truncated') }}</flux:text>
                @endif

                <pre class="{{ $outputClasses }}">{{ $viewing->output !== null && $viewing->output !== '' ? $viewing->output : __('maintenance.result.no_output') }}</pre>
            </div>
        @endif
    </flux:modal>
</section>
