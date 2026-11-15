{{--
    The record form shared by the create and edit pages (App\Livewire\Admin\EntryFormPage).

    Receives:
    - $entry           the saved record, or null on the create page;
    - $groupEntries    the live records of the group (with id, locale, title, status, published_at), for the
                       language card; on the create page the source's group, or empty;
    - $trashedEntries  the group's trashed records keyed by language (the record itself left out);
    - $localeChoices   the languages the language select offers (EntryFormPage::localeChoices()); with fewer than
                       two the language is shown as fixed.

    Everything the browser does without a request (the slug following the title, the address prefix, the search
    preview, the leave-page warning, Ctrl/Cmd+S) is the Alpine component `entryForm` (resources/js/entry-form.js).
--}}
@php
    $contentType = $this->contentType;
    $abilities = $this->abilities;
    $readOnly = $this->readOnly();
    $trashed = $entry?->trashed() ?? false;
    $timezone = (string) config('admin.timezone');
    $languages = $this->languages;
    $multilingual = count($languages) > 1;
    $parentOptions = $this->parentOptions;
    $localFields = $contentType->localFields();
    $sharedFields = $contentType->sharedFields();
    $sharedLocked = ! $readOnly && $this->sharedFieldsLocked();
    $images = $this->editorImages;
    $fullPrefix = $this->fullPrefix();
    $state = $entry?->state();
    $languageCodes = array_map(fn ($language) => $language->code, $languages);
    $present = collect($groupEntries)->whereIn('locale', $languageCodes);
    $card = 'rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900/40';
    $config = [
        'readonly' => $readOnly,
        'slugFollows' => $entry === null,
        'prefix' => $this->addressPrefix,
        'siteName' => (string) config('app.name'),
        'nowLocal' => now()->tz($timezone)->format('Y-m-d\TH:i'),
        'editorModels' => collect($contentType->fields())->filter(fn ($field) => $field instanceof App\Support\Content\Fields\RichText)->map(fn ($field) => $field->name)->values()->all(),
        'leaveModal' => 'leave-entry-form',
        'savedLocale' => $entry?->locale,
        'savedState' => $entry === null ? null : strtolower($state->name),
        'localeRefused' => __('content.form.language.refused'),
        'canSaveAndLeave' => $entry !== null,
        'saveLabels' => [
            'save' => __('content.form.save'),
            'publish' => __('content.form.save_publish'),
            'schedule' => __('content.form.save_schedule'),
            'draft' => __('content.form.save_draft'),
        ],
    ];
    $source = $entry === null && $fromId !== null ? collect($groupEntries)->firstWhere('id', $fromId) : null;
    $activeCodes = app(App\Support\Localization\ContentLocales::class)->activeCodes();
@endphp

<section
    class="w-full max-w-6xl"
    x-data="entryForm(@js($config))"
    x-on:keydown.window="onKeydown($event)"
    x-on:rich-text:changed="editorChanged = true"
    x-on:entry-form-invalid.window="leaveAfterSave = false; scrollToError()"
    x-on:entry-saved.window="onSaved($event.detail)"
    x-on:livewire:navigate.document="onNavigate($event)"
    x-on:beforeunload.window="onUnload($event)"
    data-readonly="{{ $readOnly ? 'true' : 'false' }}"
    data-test="entry-form"
>
    <form wire:submit="save" x-ref="form" class="space-y-6" novalidate>
        {{-- Header --}}
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <a href="{{ route('admin.content.index', ['type' => $type]) }}" wire:navigate class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-white" data-test="back-to-list">
                    <flux:icon.arrow-left variant="micro" class="rtl:-scale-x-100" />
                    {{ $contentType->pluralLabel() }}
                </a>
                <div class="mt-1 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1">
                    <flux:heading size="xl" level="1" class="min-w-0 break-words">{{ $entry?->title ?? $contentType->newLabel() }}</flux:heading>
                    @if ($trashed)
                        <flux:badge size="sm" color="red" icon="trash">{{ __('content.form.trashed.badge') }}</flux:badge>
                    @elseif ($state === App\Enums\EntryState::Draft)
                        <flux:badge size="sm" color="zinc">{{ __('content.list.state.draft') }}</flux:badge>
                    @elseif ($state === App\Enums\EntryState::Scheduled)
                        <flux:badge size="sm" color="sky">{{ __('content.list.state.scheduled') }}</flux:badge>
                    @endif
                </div>
                @if ($multilingual && ($entry !== null || $present->isNotEmpty()))
                    {{-- The languages of the page at a glance, each a link to that translation. --}}
                    <div class="mt-2" data-test="header-translations">
                        @include('partials.admin.content.translation-chips', [
                            'type' => $type,
                            'languages' => $languages,
                            'current' => $entry?->locale,
                            'records' => $groupEntries,
                            'trashed' => $trashedEntries,
                            'canCreate' => $abilities['create'] && ! $trashed,
                            'fromId' => $fromId,
                            'activeCodes' => $activeCodes,
                        ])
                    </div>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($entry !== null && ! $trashed)
                    {{-- A record that is hidden, or in a language that is switched off, opens only as a preview for
                         those who may edit the type; visitors get 404 there. --}}
                    @php $languageOn = app(App\Support\Localization\ContentLocales::class)->isActive($entry->locale); @endphp
                    @unless ($languageOn)
                        <flux:text class="max-w-64 text-xs" data-test="language-off-note">{{ __('content.form.language_off') }}</flux:text>
                    @endunless
                    @if (($languageOn && $entry->isPublic()) || $abilities['edit'])
                        <flux:button :href="$entry->url()" target="_blank" icon:trailing="arrow-top-right-on-square" data-test="view-entry">
                            {{ $languageOn && $entry->isPublic() ? __('content.form.view') : __('content.form.preview') }}
                        </flux:button>
                    @endif
                @endif
                @unless ($readOnly)
                    <flux:button type="submit" variant="primary" data-test="save-entry"><span x-text="saveLabel()">{{ __('content.form.save') }}</span></flux:button>
                @endunless
            </div>
        </div>

        @foreach (['form.locale', 'form.source'] as $formError)
            @error($formError)
                <flux:callout variant="danger" icon="exclamation-triangle" data-test="form-error-callout">
                    <flux:callout.text>{{ $message }}</flux:callout.text>
                </flux:callout>
            @enderror
        @endforeach

        @if ($source !== null)
            {{-- A translation opens as a copy of its source: say so, since Save may put it on the site. --}}
            <flux:callout icon="language" data-test="translation-callout">
                <flux:callout.heading>{{ __('content.form.translation.title', ['title' => $source->title, 'language' => mb_strtoupper($source->locale)]) }}</flux:callout.heading>
                <flux:callout.text>{{ $abilities['edit'] ? __('content.form.translation.text') : __('content.form.translation.text_draft') }}</flux:callout.text>
            </flux:callout>
        @endif

        @if ($trashed)
            <flux:callout variant="warning" icon="trash" data-test="trashed-callout">
                <flux:callout.heading>{{ __('content.form.trashed.title') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('content.form.trashed.removal', ['date' => $entry->deleted_at->copy()->addDays((int) config('content.trash_days'))->tz($timezone)->isoFormat('LL')]) }}
                    {{ $abilities['edit'] ? __('content.form.trashed.restore_hint') : __('content.form.trashed.no_restore') }}
                </flux:callout.text>
                @if ($abilities['edit'])
                    <x-slot name="actions">
                        <flux:button size="sm" icon="arrow-uturn-left" wire:click="restore" data-test="restore-entry">{{ __('content.list.actions.restore') }}</flux:button>
                    </x-slot>
                @endif
            </flux:callout>
        @elseif ($readOnly)
            <flux:callout icon="eye" data-test="read-only-callout">
                <flux:callout.text>{{ __('content.form.read_only') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            {{-- Left: this language --}}
            <div class="min-w-0 space-y-6">
                <div class="{{ $card }} space-y-6 p-5">
                    <flux:input
                        wire:model="form.title"
                        :label="__('content.form.title')"
                        x-on:input.debounce.400ms="followTitle()"
                        :disabled="$readOnly"
                        class:input="text-lg! font-medium"
                        maxlength="255"
                        data-test="entry-title"
                    />

                    <flux:field>
                        <flux:label>{{ __('content.form.address') }}</flux:label>
                        <flux:input.group>
                            <flux:input.group.prefix class="min-w-0 max-w-[55%] sm:max-w-[65%]">
                                <span class="block truncate [direction:rtl]" title="{{ $fullPrefix }}" x-bind:title="addressPrefix()"><bdi dir="ltr" x-text="addressPrefix()">{{ $fullPrefix }}</bdi></span>
                            </flux:input.group.prefix>
                            <flux:input wire:model="form.slug" x-on:input="onSlugInput($event)" :disabled="$readOnly" :aria-label="__('content.form.slug')" maxlength="255" autocomplete="off" spellcheck="false" data-test="entry-slug" />
                        </flux:input.group>
                        <flux:error name="form.slug" />
                        @unless ($readOnly)
                            <flux:description x-show="slugFollows" x-cloak>{{ __('content.form.slug_follows') }}</flux:description>
                        @endunless
                    </flux:field>

                    <x-admin.rich-text wire:model="form.body" :label="__('content.form.body')" :images="$images" :locale="$locale" :readonly="$readOnly" />

                    <flux:textarea wire:model="form.excerpt" :label="__('content.form.excerpt')" :description:trailing="__('content.form.excerpt_hint')" :disabled="$readOnly" rows="3" resize="vertical" maxlength="1000" />
                </div>

                @if ($localFields !== [])
                    <div class="{{ $card }} p-5" data-test="local-fields">
                        <flux:heading level="2" class="mb-5">{{ __('content.form.fields_card') }}</flux:heading>
                        <div class="space-y-6">
                            @foreach ($localFields as $field)
                                <div wire:key="field-{{ $field->name }}" data-test="field-{{ $field->name }}">
                                    @include('partials.admin.content.fields.'.$field->view(), ['field' => $field, 'model' => 'form.fields.'.$field->name, 'readOnly' => $readOnly, 'images' => $images, 'locale' => $locale])
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="{{ $card }} p-5">
                    <flux:heading level="2">{{ __('content.form.seo.title') }}</flux:heading>
                    <flux:text class="mt-1 text-sm">{{ __('content.form.seo.description') }}</flux:text>

                    <div class="mt-4 rounded-lg bg-zinc-50 p-4 dark:bg-white/5" aria-hidden="true" data-test="seo-preview">
                        <div class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="seoAddress()"></div>
                        <div class="mt-0.5 truncate text-base text-accent-content" x-text="seoTitle()"></div>
                        <div class="mt-0.5 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400" x-text="seoDescription() || @js(__('content.form.seo.no_description'))"></div>
                    </div>

                    <div class="mt-5 space-y-6">
                        <flux:input wire:model="form.seo_title" :label="__('content.form.seo.meta_title')" :placeholder="__('content.form.seo.meta_title_placeholder')" :disabled="$readOnly" maxlength="255" />
                        <flux:textarea wire:model="form.seo_description" :label="__('content.form.seo.meta_description')" :placeholder="__('content.form.seo.meta_description_placeholder')" :disabled="$readOnly" rows="2" resize="vertical" maxlength="500" />
                    </div>
                </div>
            </div>

            {{-- Right: publication and what the record shares --}}
            <div class="min-w-0 space-y-6">
                <div class="{{ $card }} space-y-5 p-5">
                    <flux:heading level="2">{{ __('content.form.publication.title') }}</flux:heading>

                    <div>
                        <flux:select wire:model="form.status" :label="__('content.form.publication.status')" :disabled="$readOnly || ! $abilities['edit']">
                            <flux:select.option value="draft">{{ __('content.form.publication.draft') }}</flux:select.option>
                            <flux:select.option value="published">{{ __('content.form.publication.published') }}</flux:select.option>
                        </flux:select>
                        @if (! $abilities['edit'] && ! $readOnly)
                            <flux:text class="mt-2 text-xs" data-test="status-locked">{{ __('content.form.publication.draft_only') }}</flux:text>
                        @endif
                    </div>

                    <div>
                        <flux:input type="datetime-local" wire:model="form.published_at" :label="__('content.form.publication.date')" :disabled="$readOnly || ! $abilities['edit']" />
                        <flux:text class="mt-2 text-xs">{{ __('content.form.publication.timezone', ['timezone' => str_replace('_', ' ', $timezone)]) }}</flux:text>
                        @unless ($readOnly)
                            <flux:text class="mt-1 text-xs text-sky-700! dark:text-sky-300!" x-show="isScheduled()" x-cloak data-test="scheduled-hint">{{ __('content.form.publication.scheduled_hint') }}</flux:text>
                            <flux:text class="mt-1 text-xs" x-show="publishesNow()" x-cloak>{{ __('content.form.publication.now_hint') }}</flux:text>
                        @endunless
                    </div>
                </div>

                @if ($multilingual)
                    @php
                        $language = $this->language;
                        $total = count($languages);
                    @endphp
                    <div class="{{ $card }} p-5" data-test="translations-card">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <flux:heading level="2">{{ __('content.form.language.title') }}</flux:heading>
                            @if ($present->isNotEmpty())
                                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ __('content.list.translations_summary', ['count' => $present->count(), 'total' => $total]) }}</span>
                            @endif
                        </div>

                        @if (! $readOnly && count($localeChoices ?? []) > 1)
                            {{-- The language is a choice (a saved record takes it on Save). Not `wire:model` (the
                                 language is locked); the form's script asks the server and keeps the prefix and the
                                 editors in step. --}}
                            <div class="mb-4">
                                <flux:select :label="__('content.form.language.choose')" x-on:change="changeLocale($event.target)" data-test="entry-locale">
                                    @php $offered = array_map(fn ($choice) => $choice->code, $localeChoices); @endphp
                                    {{-- A language the group already has stays in the list, disabled, with the reason. --}}
                                    @foreach ($languages as $choice)
                                        @php $taken = ! in_array($choice->code, $offered, true); @endphp
                                        <flux:select.option :value="$choice->code" :selected="$choice->code === $locale" :disabled="$taken" wire:key="locale-choice-{{ $choice->code }}" data-test="locale-choice-{{ $choice->code }}" data-taken="{{ $taken ? 'true' : 'false' }}">
                                            {{ $choice->native }} ({{ strtoupper($choice->code) }}){{ in_array($choice->code, $activeCodes, true) ? '' : ' · '.__('content.list.off') }}{{ $taken ? ' · '.__('content.form.language.taken') : '' }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                                @if ($entry !== null)
                                    <flux:text class="mt-2 text-xs" data-test="entry-locale-hint">{{ __('content.form.language.choose_hint') }}</flux:text>
                                @endif
                            </div>
                        @else
                            <div class="mb-4 flex items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-white/5">
                                <span class="rounded bg-zinc-900 px-1.5 py-0.5 text-[11px] font-semibold text-white uppercase dark:bg-white dark:text-zinc-900">{{ $language->code }}</span>
                                <span class="min-w-0 flex-1 truncate font-medium text-zinc-900 dark:text-white">{{ $language->native }}</span>
                                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ __('content.form.language.this') }}</span>
                            </div>
                            @if ($entry !== null && ! $readOnly && $this->hasDescendants)
                                <flux:text class="-mt-2 mb-4 text-xs" data-test="entry-locale-locked">{{ __('content.form.language.locked_children') }}</flux:text>
                            @endif
                        @endif

                        @if ($entry !== null || $present->isNotEmpty())
                            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                                <flux:heading class="mb-3 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">{{ __('content.translations.title') }}</flux:heading>

                                @include('partials.admin.content.translations-list', [
                                    'entry' => $entry,
                                    'type' => $type,
                                    'locale' => $entry?->locale ?? $locale,
                                    'languages' => $languages,
                                    'groupEntries' => $groupEntries,
                                    'trashedEntries' => $trashedEntries,
                                    'canCreate' => $abilities['create'] && ! $trashed,
                                    'canLink' => $entry !== null && ! $readOnly,
                                    'fromId' => $fromId,
                                ])

                                @if ($entry !== null && ! $readOnly && collect($groupEntries)->count() + collect($trashedEntries)->count() > 2)
                                    <div class="mt-4 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                        <flux:button size="xs" variant="subtle" icon="link-slash" x-on:click="$wire.confirmUnlink({{ $entry->id }})" data-test="unlink-translation">{{ __('content.link.unlink_self') }}</flux:button>
                                    </div>
                                @endif
                            </div>
                        @else
                            <flux:text class="text-sm">{{ __('content.form.language.after_save') }}</flux:text>
                        @endif
                    </div>
                @endif

                @if ($sharedFields !== [])
                    <div class="{{ $card }}" data-test="shared-fields">
                        <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                            <flux:heading level="2">{{ __('content.form.shared.title') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('content.form.shared.description') }}</flux:text>
                            @if ($sharedLocked)
                                <flux:text class="mt-2 text-xs" data-test="shared-locked">{{ __('content.form.shared.locked') }}</flux:text>
                            @endif
                        </div>
                        <div class="space-y-6 p-5">
                            @foreach ($sharedFields as $field)
                                <div wire:key="field-{{ $field->name }}" data-test="field-{{ $field->name }}">
                                    @include('partials.admin.content.fields.'.$field->view(), ['field' => $field, 'model' => 'form.fields.'.$field->name, 'readOnly' => $readOnly || $sharedLocked, 'images' => $images, 'locale' => $locale])
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="{{ $card }} space-y-3 p-5">
                    <flux:heading level="2">{{ __('content.form.image.title') }}</flux:heading>
                    @if ($readOnly)
                        @include('partials.admin.content.fields.read-only-media', ['label' => __('content.form.image.title'), 'ids' => $form->image_id !== null ? [$form->image_id] : []])
                    @else
                        <livewire:media-picker wire:model="form.image_id" accept="image" key="entry-image-picker" />
                        <flux:error name="form.image_id" />
                    @endif
                    <flux:text class="text-xs">{{ __('content.form.image.hint') }}</flux:text>
                </div>

                <div class="{{ $card }} space-y-5 p-5">
                    <flux:heading level="2">{{ __('content.form.attributes') }}</flux:heading>

                    @if ($contentType->hierarchical())
                        <flux:select wire:model="form.parent_id" :label="__('content.form.parent')" x-on:change="onParentChange()" :disabled="$readOnly" data-test="entry-parent">
                            <flux:select.option value="">{{ __('content.form.no_parent') }}</flux:select.option>
                            @foreach ($parentOptions as $option)
                                <flux:select.option :value="(string) $option['id']" wire:key="parent-{{ $option['id'] }}" data-path="{{ $option['path'] }}" data-test="parent-option-{{ $option['id'] }}">{{ str_repeat("\u{00A0}\u{00A0}\u{00A0}", min($option['depth'], 8)) }}{{ $option['depth'] > 0 ? '└ ' : '' }}{{ $option['title'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif

                    @if ($abilities['edit'] && ! $readOnly)
                        <flux:select wire:model="form.author_id" :label="__('content.form.author')">
                            <flux:select.option value="">{{ __('content.form.no_author') }}</flux:select.option>
                            @foreach ($this->authorOptions as $id => $name)
                                <flux:select.option :value="(string) $id" wire:key="author-{{ $id }}">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @else
                        <div data-test="author-readonly">
                            <flux:label>{{ __('content.form.author') }}</flux:label>
                            <p class="mt-2 text-sm text-zinc-800 dark:text-zinc-200">{{ $this->authorName ?? '—' }}</p>
                            <flux:error name="form.author_id" />
                        </div>
                    @endif
                </div>

                @if ($entry !== null && ! $readOnly && $abilities['delete'])
                    <button type="button" wire:click="confirmTrash" class="flex w-full items-center justify-center gap-2 rounded-lg border border-red-200 px-4 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-500 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10" data-test="trash-entry">
                        <flux:icon.trash variant="micro" />
                        {{ __('content.list.actions.trash') }}
                    </button>
                @endif
            </div>
        </div>

        @unless ($readOnly)
            <div class="sticky bottom-4 z-10 flex items-center justify-end gap-3 rounded-xl border border-zinc-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95" data-test="save-bar">
                <span class="me-auto flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400" x-show="isDirty()" x-cloak>
                    <span class="size-2 rounded-full bg-amber-400"></span>{{ __('content.form.unsaved') }}
                </span>
                <span class="hidden text-xs text-zinc-400 sm:inline dark:text-zinc-500" x-text="shortcutHint(@js(__('content.form.shortcut')))"></span>
                <flux:button type="submit" variant="primary"><span x-text="saveLabel()">{{ __('content.form.save') }}</span></flux:button>
            </div>
        @endunless
    </form>

    @if ($entry !== null && ! $readOnly && $abilities['delete'])
        <x-confirm-modal
            wire:model.self="showTrashModal"
            :heading="__('content.list.trash.title')"
            :confirm-label="__('content.list.trash.submit')"
            confirm-action="trash"
        >
            @if ($this->trashingChildren > 0)
                {{ trans_choice('content.list.trash.confirm_children', $this->trashingChildren, ['title' => $entry->title]) }}
            @else
                {{ trans_choice('content.list.trash.confirm', (int) config('content.trash_days'), ['title' => $entry->title]) }}
            @endif
        </x-confirm-modal>
    @endif

    @if ($entry !== null && ! $readOnly && $multilingual)
        @php
            $unlinkTarget = $this->unlinkId > 0 ? $this->unlinkTarget($this->unlinkId) : null;
        @endphp

        <livewire:translation-picker :type="$type" :entry-id="$entry->id" key="translation-picker" />

        <x-confirm-modal
            wire:model.self="showUnlinkModal"
            :heading="__('content.link.unlink_title')"
            :confirm-label="__('content.link.unlink')"
            confirm-action="unlink"
        >
            {{ __('content.link.unlink_confirm', ['title' => $unlinkTarget?->title ?? '']) }}
        </x-confirm-modal>
    @endif

    @unless ($readOnly)
        <livewire:media-picker detached accept="image" key="entry-detached-picker" />

        <flux:modal name="leave-entry-form" class="max-w-md md:min-w-md">
            <div class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('content.form.leave.title') }}</flux:heading>
                    <flux:text>{{ __('content.form.leave.body') }}</flux:text>
                </div>
                <div class="flex flex-wrap justify-end gap-3">
                    <flux:modal.close>
                        <flux:button variant="outline">{{ __('common.actions.cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" x-on:click="leave()">{{ __('content.form.leave.submit') }}</flux:button>
                    @if ($entry !== null)
                        <flux:button variant="primary" x-on:click="saveAndLeave()" data-test="save-and-leave">{{ __('content.form.leave.save') }}</flux:button>
                    @endif
                </div>
            </div>
        </flux:modal>
    @endunless
</section>
