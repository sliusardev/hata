<?php

use App\Actions\Content\LinkTranslations;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Support\Localization\ContentLocales;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The dialog of a record's edit form that chooses an existing record as its translation in one language. A
 * component of its own, so typing in its search renders only the dialog, never the form around it. Opened by the
 * `choose-translation` event (`locale`); after linking it tells the page with `translations-changed`.
 */
new class extends Component {
    /**
     * How many candidates the dialog lists; one more is asked for, to say that there are more.
     */
    public const int LIMIT = 20;

    #[Locked]
    public string $type = '';

    #[Locked]
    public int $entryId = 0;

    public bool $open = false;

    #[Locked]
    public string $locale = '';

    public string $search = '';

    /**
     * A candidate that is someone's translation already, waiting for a second click: choosing it takes it away
     * from those records.
     */
    #[Locked]
    public int $confirmingId = 0;

    public function mount(string $type, int $entryId): void
    {
        $this->type = $type;
        $this->entryId = $entryId;
    }

    #[Computed]
    public function entry(): Entry
    {
        return Entry::query()->ofType($this->type)->findOrFail($this->entryId);
    }

    /**
     * Opens the dialog for a language the record's group lacks (in the trash too).
     */
    #[On('choose-translation')]
    public function choose(string $locale): void
    {
        $this->authorize('update', $this->entry);

        abort_if(! app(ContentLocales::class)->has($locale) || in_array($locale, EntryGroup::takenLocales($this->entry->entry_group_id), true), 404);

        $this->locale = $locale;
        $this->search = '';
        $this->confirmingId = 0;
        $this->open = true;
    }

    /**
     * Takes back the first click on a record that is someone's translation.
     */
    public function cancelConfirmation(): void
    {
        $this->confirmingId = 0;
    }

    public function updatedSearch(): void
    {
        $this->confirmingId = 0;
    }

    /**
     * Live records of the type in the chosen language, outside the group. One query while the dialog is open.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function candidates(): Collection
    {
        if (! $this->open || $this->locale === '') {
            return collect();
        }

        return Entry::query()
            ->ofType($this->type)
            ->where('locale', $this->locale)
            ->where('entry_group_id', '!=', $this->entry->entry_group_id)
            ->search($this->search)
            ->with('groupEntries:id,entry_group_id,locale')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get(['id', 'entry_group_id', 'type', 'locale', 'title', 'path', 'status', 'published_at']);
    }

    /**
     * Links the record at once, or asks first when it is linked to other translations, which it would leave.
     */
    public function pick(int $id, LinkTranslations $linkTranslations): void
    {
        $this->authorize('update', $this->entry);

        $other = Entry::query()->ofType($this->type)->with('groupEntries:id,entry_group_id,locale')->findOrFail($id);

        if ($other->groupEntries->count() > 1 && $this->confirmingId !== $id) {
            $this->confirmingId = $id;

            return;
        }

        try {
            $linkTranslations->handle(Auth::user(), $this->entry, $other);
        } catch (ProtectedActionException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
            $this->confirmingId = 0;
            unset($this->candidates);

            return;
        }

        $this->reset('open', 'search', 'confirmingId');

        Flux::toast(variant: 'success', text: __('content.link.linked', ['title' => $other->title]));

        $this->dispatch('translations-changed');
    }
}; ?>

<div>
    <flux:modal wire:model.self="open" class="w-full max-w-lg" data-test="link-modal">
        <div class="space-y-5">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('content.link.title', ['language' => app(App\Support\Localization\ContentLocales::class)->language($locale !== '' ? $locale : 'en')->native]) }}</flux:heading>
                <flux:text>{{ __('content.link.description') }}</flux:text>
            </div>

            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('content.link.search')" clearable data-test="link-search" />

            <div class="max-h-80 space-y-1 overflow-y-auto" wire:loading.class="opacity-60">
                @forelse ($this->candidates->take($this::LIMIT) as $candidate)
                    @php
                        $candidateState = $candidate->state();
                        $others = $candidate->translations()->pluck('locale');
                    @endphp
                    <div wire:key="link-candidate-{{ $candidate->id }}">
                        <button type="button" wire:click="pick({{ $candidate->id }})" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-start hover:bg-zinc-100 dark:hover:bg-white/10" data-test="link-candidate-{{ $candidate->id }}">
                            <span @class(['size-2 shrink-0 rounded-full', 'bg-green-500' => $candidateState === App\Enums\EntryState::Published, 'bg-amber-400' => $candidateState !== App\Enums\EntryState::Published]) title="{{ __('content.list.state.'.strtolower($candidateState->name)) }}"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $candidate->title }}</span>
                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">/{{ $candidate->path }}</span>
                            </span>
                            @if ($others->isNotEmpty())
                                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ __('content.link.linked_to', ['languages' => mb_strtoupper($others->implode(', '))]) }}</span>
                            @endif
                        </button>

                        @if ($confirmingId === $candidate->id)
                            <div class="mx-3 mb-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" data-test="link-confirm">
                                <p>{{ __('content.link.leaves', ['languages' => mb_strtoupper($others->implode(', '))]) }}</p>
                                <div class="mt-3 flex gap-2">
                                    <flux:button size="xs" variant="primary" wire:click="pick({{ $candidate->id }})" data-test="link-confirm-button">{{ __('content.link.confirm') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="cancelConfirmation" data-test="link-confirm-cancel">{{ __('common.actions.cancel') }}</flux:button>
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <flux:text class="px-3 py-6 text-center text-sm">{{ $search === '' ? __('content.link.empty') : __('content.link.not_found') }}</flux:text>
                @endforelse
            </div>

            @if ($this->candidates->count() > $this::LIMIT)
                <flux:text class="text-xs" data-test="link-more">{{ __('content.link.more', ['count' => $this::LIMIT]) }}</flux:text>
            @endif

            @if ($open && $this->entry->type()->sharedFields() !== [])
                <flux:text class="text-xs">{{ __('content.link.shared_note') }}</flux:text>
            @endif

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</div>
