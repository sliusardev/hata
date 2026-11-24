<?php

use Flux\Flux;
use Hata\Actions\Settings\SetContentTypeActive;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Models\Entry;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

new class extends SettingsSectionPage {
    /**
     * The content type the "switch off" confirmation is about.
     */
    #[Locked]
    public string $contentTypeOff = '';

    public bool $showContentTypeModal = false;

    public static function key(): string
    {
        return 'content';
    }

    protected function fields(): array
    {
        return [];
    }

    protected function rules(): array
    {
        return [];
    }

    /**
     * Every registered content type, whether it is on and how many records it holds (the trash included). One
     * grouped query, only for the Content types tab.
     *
     * @return list<array{type: Hata\Support\Content\ContentType, active: bool, count: int, taxonomies: list<string>}>
     */
    #[Computed]
    public function contentTypeRows(): array
    {
        $types = app(ContentTypes::class);
        $counts = Entry::withTrashed()->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        // A taxonomy has no row: it goes on and off with the type it classifies, and is named under it.
        return array_values(array_map(fn ($type): array => [
            'type' => $type,
            'active' => $types->isActive($type->key()),
            'count' => (int) ($counts[$type->key()] ?? 0),
            'taxonomies' => array_values(array_map(fn ($taxonomy): string => $taxonomy->pluralLabel(), $types->taxonomiesOf($type->key()))),
        ], array_filter($types->all(), fn ($type): bool => ! $type->isTaxonomy())));
    }

    /**
     * Switches a type on at once; switching one off asks first, as its pages leave the site.
     */
    public function toggleContentType(string $key, SetContentTypeActive $setContentTypeActive): void
    {
        $this->authorizeSection();

        if (app(ContentTypes::class)->isActive($key)) {
            $this->contentTypeOff = $key;
            $this->showContentTypeModal = true;

            return;
        }

        $this->changeContentType($setContentTypeActive, $key, true);
    }

    public function turnOffContentType(SetContentTypeActive $setContentTypeActive): void
    {
        $this->showContentTypeModal = false;

        if ($this->contentTypeOff !== '') {
            $this->changeContentType($setContentTypeActive, $this->contentTypeOff, false);
        }
    }

    /**
     * Stores the change and reloads the tab, so the sidebar is rendered with the type's item gone or back.
     */
    private function changeContentType(SetContentTypeActive $setContentTypeActive, string $key, bool $active): void
    {
        $this->authorizeSection();

        try {
            $setContentTypeActive->handle(Auth::user(), $key, $active);
        } catch (ProtectedActionException $exception) {
            $this->contentTypeOff = '';
            unset($this->contentTypeRows);
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        Toast::success(__($active ? 'hata::site_settings.content.messages.turned_on' : 'hata::site_settings.content.messages.turned_off', [
            'type' => app(ContentTypes::class)->get($key)->pluralLabel(),
        ]));

        $this->redirectRoute('admin.settings.edit', ['tab' => static::key()]);
    }
}; ?>

<div>
    <x-hata::admin.settings-section :save="false">
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900" data-test="content-types">
            <div class="border-b border-zinc-200 p-5 dark:border-white/10">
                <flux:heading level="2">{{ __('hata::site_settings.content.list_heading') }}</flux:heading>
                <flux:text size="sm" class="mt-1 max-w-prose">{{ __('hata::site_settings.content.list_description') }}</flux:text>
            </div>

            <ul class="divide-y divide-zinc-200 dark:divide-white/10">
                @foreach ($this->contentTypeRows as $row)
                    @php $type = $row['type']; @endphp
                    <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5" wire:key="content-type-{{ $type->key() }}" data-test="content-type-{{ $type->key() }}" data-active="{{ $row['active'] ? 'true' : 'false' }}">
                        <span @class(['flex size-10 shrink-0 items-center justify-center rounded-lg', 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200' => $row['active'], 'bg-zinc-50 text-zinc-300 dark:bg-white/5 dark:text-zinc-600' => ! $row['active']])>
                            <flux:icon :name="$type->icon()" variant="outline" class="size-5" />
                        </span>

                        <div class="min-w-0 flex-1 basis-48">
                            <div class="flex flex-wrap items-center gap-2">
                                <span @class(['truncate font-medium', 'text-zinc-800 dark:text-white' => $row['active'], 'text-zinc-500 dark:text-zinc-400' => ! $row['active']])>{{ $type->pluralLabel() }}</span>
                                @if ($type->required())
                                    <flux:badge size="sm" color="amber">{{ __('hata::site_settings.content.badges.required') }}</flux:badge>
                                @elseif (! $row['active'])
                                    <flux:badge size="sm" color="zinc">{{ __('hata::site_settings.content.badges.off') }}</flux:badge>
                                @endif
                            </div>
                            <flux:text size="sm">{{ $type->description() }}</flux:text>
                            @if ($row['taxonomies'] !== [])
                                <flux:text size="sm" class="mt-0.5" data-test="content-type-taxonomies-{{ $type->key() }}">{{ __('hata::site_settings.content.with', ['names' => implode(', ', $row['taxonomies'])]) }}</flux:text>
                            @endif
                        </div>

                        <div class="w-32 text-sm text-zinc-500 tabular-nums max-sm:hidden dark:text-zinc-400">{{ trans_choice('hata::site_settings.content.records', $row['count']) }}</div>

                        <div class="shrink-0">
                            {{-- A plain button, not flux:switch: it shows the stored state and asks the server to change it
                                 (`type="button"`, so it never submits the settings form it sits in). --}}
                            <button
                                type="button"
                                role="switch"
                                aria-checked="{{ $row['active'] ? 'true' : 'false' }}"
                                aria-label="{{ __('hata::site_settings.content.switch', ['type' => $type->pluralLabel()]) }}"
                                @if ($type->required())
                                    disabled
                                    title="{{ __('hata::site_settings.content.badges.required') }}"
                                @else
                                    wire:click="toggleContentType('{{ $type->key() }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleContentType, turnOffContentType"
                                    data-test="content-type-switch-{{ $type->key() }}"
                                @endif
                                @class([
                                    'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
                                    'cursor-pointer' => ! $type->required(),
                                    'bg-[var(--color-accent)]' => $row['active'],
                                    'bg-zinc-300 dark:bg-zinc-600' => ! $row['active'],
                                ])
                            >
                                <span @class([
                                    'inline-block size-5 rounded-full bg-white shadow transition-transform',
                                    'translate-x-[1.375rem] rtl:-translate-x-[1.375rem]' => $row['active'],
                                    'translate-x-0.5 rtl:-translate-x-0.5' => ! $row['active'],
                                ])></span>
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-hata::admin.settings-section>

    @php $typeOff = $contentTypeOff === '' ? null : app(ContentTypes::class)->find($contentTypeOff); @endphp
    <x-hata::confirm-modal
        wire:model.self="showContentTypeModal"
        :heading="__('hata::site_settings.content.off.title', ['type' => $typeOff?->pluralLabel() ?? ''])"
        :confirm-label="__('hata::site_settings.content.off.submit')"
        confirm-action="turnOffContentType"
        data-test="content-type-off-modal"
    >
        {{ __('hata::site_settings.content.off.body') }}
    </x-hata::confirm-modal>
</div>
