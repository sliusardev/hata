{{--
    The card of one taxonomy on a record's form: the terms of the form's language the record is filed under.

    Receives:
    - $taxonomy   the taxonomy's ContentType;
    - $options    list<array{id: int, title: string, depth: int}>, its live terms in the form's language, in tree
                  order (EntryFormPage::termOptions(), no query here);
    - $readOnly   whether the form is read-only;
    - $canCreate  whether the actor may make a new term from here (the record type's `edit`; flat taxonomies only).

    A tree taxonomy (categories) is a list of checkboxes indented by depth. A flat one (tags) is the chosen terms as
    chips and an input that filters the existing ones as you type; Enter on a name that matches none adds it as a
    new term, made when the record is saved. Both write `form.terms` (ids); new names go to `form.newTerms.<key>`.
--}}
@php
    $key = $taxonomy->key();
    $box = 'size-4 shrink-0 cursor-pointer rounded accent-zinc-800 disabled:cursor-not-allowed dark:accent-white';
@endphp

<div class="{{ $card }} p-5" data-test="terms-{{ $key }}" wire:key="terms-{{ $key }}-{{ $locale }}">
    <flux:heading level="2" class="mb-3">{{ $taxonomy->pluralLabel() }}</flux:heading>

    @if ($taxonomy->hierarchical())
        @if ($options === [])
            <flux:text class="text-sm">{{ __('content.terms.none_yet', ['language' => $this->language->native]) }}</flux:text>
        @else
            <ul class="max-h-64 space-y-1.5 overflow-y-auto pe-1">
                @foreach ($options as $option)
                    <li wire:key="term-{{ $option['id'] }}" @if ($option['depth'] > 0) style="padding-inline-start: {{ min($option['depth'], 5) * 1.25 }}rem" @endif>
                        <label class="flex cursor-pointer items-start gap-2 text-sm text-zinc-800 dark:text-zinc-200">
                            <input type="checkbox" class="{{ $box }} mt-0.5" value="{{ $option['id'] }}" x-model="$wire.form.terms" @disabled($readOnly) data-test="term-{{ $option['id'] }}" />
                            <span class="min-w-0 break-words">{{ $option['title'] }}</span>
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        <div
            x-data="{
                options: @js(array_map(fn ($option) => ['id' => (string) $option['id'], 'title' => $option['title']], $options)),
                query: '',
                open: false,
                chosen() { return this.options.filter((option) => $wire.form.terms.includes(option.id)) },
                fresh() { return ($wire.form.newTerms ?? {})[@js($key)] ?? [] },
                matches() {
                    const query = this.query.trim().toLowerCase();

                    return this.options
                        .filter((option) => ! $wire.form.terms.includes(option.id) && (query === '' || option.title.toLowerCase().includes(query)))
                        .slice(0, 8);
                },
                pick(option) { $wire.form.terms = [...$wire.form.terms, option.id]; this.query = ''; },
                drop(option) { $wire.form.terms = $wire.form.terms.filter((id) => id !== option.id) },
                dropFresh(name) { $wire.set('form.newTerms.' + @js($key), this.fresh().filter((other) => other !== name), false) },
                add() {
                    const name = this.query.trim();

                    if (name === '') { return }

                    const same = this.options.find((option) => option.title.toLowerCase() === name.toLowerCase());

                    if (same) {
                        if (! $wire.form.terms.includes(same.id)) { this.pick(same) }
                    } else if (@js($canCreate) && ! this.fresh().some((other) => other.toLowerCase() === name.toLowerCase())) {
                        $wire.set('form.newTerms.' + @js($key), [...this.fresh(), name], false);
                    }

                    this.query = '';
                },
            }"
            x-on:click.outside="open = false"
        >
            <div class="flex flex-wrap gap-1.5" x-show="chosen().length > 0 || fresh().length > 0" x-cloak>
                <template x-for="option in chosen()" :key="option.id">
                    <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-zinc-100 py-0.5 ps-2 pe-1 text-sm text-zinc-800 dark:bg-white/10 dark:text-zinc-100" data-test="term-chip">
                        <span class="truncate" x-text="option.title"></span>
                        @unless ($readOnly)
                            <button type="button" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-white" x-on:click="drop(option)" :aria-label="@js(__('content.terms.remove')) + ' ' + option.title">
                                <flux:icon.x-mark variant="micro" />
                            </button>
                        @endunless
                    </span>
                </template>
                <template x-for="name in fresh()" :key="'new-' + name">
                    <span class="inline-flex max-w-full items-center gap-1 rounded-md border border-dashed border-zinc-300 py-0.5 ps-2 pe-1 text-sm text-zinc-700 dark:border-zinc-600 dark:text-zinc-200" title="{{ __('content.terms.new_hint') }}" data-test="term-chip-new">
                        <span class="truncate" x-text="name"></span>
                        <button type="button" class="rounded p-0.5 text-zinc-400 hover:text-zinc-700 dark:hover:text-white" x-on:click="dropFresh(name)" :aria-label="@js(__('content.terms.remove')) + ' ' + name">
                            <flux:icon.x-mark variant="micro" />
                        </button>
                    </span>
                </template>
            </div>

            @unless ($readOnly)
                <div class="relative mt-3">
                    <flux:input
                        x-model="query"
                        x-on:focus="open = true"
                        x-on:input="open = true"
                        x-on:keydown.enter.prevent="add()"
                        x-on:keydown.escape="open = false"
                        size="sm"
                        icon="tag"
                        :placeholder="$canCreate ? __('content.terms.placeholder_create') : __('content.terms.placeholder')"
                        :aria-label="$taxonomy->pluralLabel()"
                        autocomplete="off"
                        data-test="term-input-{{ $key }}"
                    />

                    <ul x-show="open && matches().length > 0" x-cloak class="absolute inset-x-0 z-20 mt-1 max-h-56 overflow-y-auto rounded-lg border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                        <template x-for="option in matches()" :key="option.id">
                            <li>
                                <button type="button" class="block w-full truncate rounded-md px-2 py-1.5 text-start text-sm text-zinc-800 hover:bg-zinc-100 dark:text-zinc-100 dark:hover:bg-white/10" x-on:click="pick(option)" x-text="option.title"></button>
                            </li>
                        </template>
                    </ul>
                </div>
                @if ($canCreate)
                    <flux:text class="mt-2 text-xs">{{ __('content.terms.create_hint') }}</flux:text>
                @endif
            @endunless
        </div>
    @endif

    <flux:error name="form.terms" />
</div>
