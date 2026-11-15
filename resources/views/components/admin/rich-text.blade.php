{{--
    Rich text editor (Tiptap) bound to a Livewire property:
    <x-admin.rich-text wire:model="form.body" :label="__('…')" :images="$bodyImages" :locale="$entry->locale" :readonly="false" />

    - The editor writes the HTML into the property without a request (debounced, and again before any Livewire action
      of the component), '' for an empty document; it never reads the property back after loading.
    - `images`: id => current URL of every image in the body, so the editor can show them (the body stores only ids).
    - `locale`: the content language, whose alt text the media picker hands back with a chosen image.
    - The image button needs media.view and one detached media picker on the page:
      <livewire:media-picker detached accept="image" />
    - The editor lives in wire:ignore: a re-render of the component never resets it or takes the focus.
--}}
@props([
    'label' => null,
    'readonly' => false,
    'images' => [],
    'locale' => null,
])

@php
    $model = (string) $attributes->wire('model')->value();
    $key = 'rich-text-'.Illuminate\Support\Str::slug(str_replace('.', '-', $model));
    $labelId = $label ? $key.'-label' : null;
    $canPickImages = ! $readonly && (auth()->user()?->can('viewAny', App\Models\Media::class) ?? false);
    $config = [
        'model' => $model,
        'target' => 'rich-text:'.$model,
        'id' => $key,
        'images' => collect($images)->mapWithKeys(fn ($url, $id): array => [(string) $id => (string) $url])->all(),
        'readonly' => (bool) $readonly,
        'locale' => (string) $locale,
        'labelledBy' => $labelId,
    ];
    $buttonClass = 'inline-flex h-8 min-w-8 items-center justify-center rounded-md px-1.5 text-sm text-zinc-600 transition-colors hover:bg-zinc-200/70 hover:text-zinc-900 focus-visible:outline-2 focus-visible:outline-accent disabled:pointer-events-none disabled:opacity-40 dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white';
    $activeClass = 'bg-zinc-200 text-zinc-900 dark:bg-white/15 dark:text-white';
    $groups = [
        [
            ['command' => 'bold', 'label' => __('content.editor.bold'), 'icon' => 'bold'],
            ['command' => 'italic', 'label' => __('content.editor.italic'), 'icon' => 'italic'],
            ['command' => 'underline', 'label' => __('content.editor.underline'), 'icon' => 'underline'],
            ['command' => 'strike', 'label' => __('content.editor.strike'), 'icon' => 'strikethrough'],
        ],
        [
            ['command' => 'bulletList', 'label' => __('content.editor.bullet_list'), 'icon' => 'list-bullet'],
            ['command' => 'orderedList', 'label' => __('content.editor.ordered_list'), 'icon' => 'numbered-list'],
            ['command' => 'blockquote', 'label' => __('content.editor.blockquote'), 'text' => '❝'],
        ],
    ];
@endphp

<div {{ $attributes->whereDoesntStartWith('wire:model')->class('space-y-2') }} data-test="rich-text">
    @if ($label)
        <flux:label id="{{ $labelId }}">{{ $label }}</flux:label>
    @endif

    <div
        wire:ignore
        x-data="richText(@js($config))"
        @unless ($readonly) x-on:media-picker:picked.window="onPicked($event.detail)" x-on:entry-locale:changed.window="setLocale($event.detail.locale)" @endunless
        @class([
            'overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-xs dark:border-white/10 dark:bg-white/5',
            'border-b-zinc-300/80 has-[.ProseMirror-focused]:ring-2 has-[.ProseMirror-focused]:ring-accent has-[.ProseMirror-focused]:ring-offset-2 has-[.ProseMirror-focused]:ring-offset-accent-foreground' => ! $readonly,
        ])
    >
        @unless ($readonly)
            <div role="toolbar" aria-label="{{ __('content.editor.toolbar') }}" aria-controls="{{ $key }}" class="flex flex-wrap items-center gap-1 border-b border-zinc-200 bg-zinc-50 px-2 py-1.5 dark:border-white/10 dark:bg-white/5">
                <select
                    x-model="block"
                    x-on:change="setBlock($event.target.value)"
                    x-bind:disabled="!ready"
                    aria-label="{{ __('content.editor.block') }}"
                    class="h-8 rounded-md border-0 bg-transparent ps-2 pe-7 text-sm text-zinc-700 hover:bg-zinc-200/70 focus-visible:outline-2 focus-visible:outline-accent disabled:opacity-40 dark:text-zinc-200 dark:hover:bg-white/10 [&>option]:text-zinc-800"
                    data-test="rich-text-block"
                >
                    <option value="paragraph">{{ __('content.editor.paragraph') }}</option>
                    <option value="h2">{{ __('content.editor.heading_2') }}</option>
                    <option value="h3">{{ __('content.editor.heading_3') }}</option>
                    <option value="h4">{{ __('content.editor.heading_4') }}</option>
                </select>

                @foreach ($groups as $group)
                    <span class="mx-1 h-5 w-px bg-zinc-200 dark:bg-white/10" aria-hidden="true"></span>
                    <div class="flex items-center gap-0.5">
                        @foreach ($group as $button)
                            <flux:tooltip :content="$button['label']">
                                <button
                                    type="button"
                                    x-on:mousedown.prevent
                                    x-on:click="command('{{ $button['command'] }}')"
                                    x-bind:class="active.{{ $button['command'] }} && '{{ $activeClass }}'"
                                    x-bind:aria-pressed="active.{{ $button['command'] }} ? 'true' : 'false'"
                                    x-bind:disabled="!ready"
                                    aria-label="{{ $button['label'] }}"
                                    class="{{ $buttonClass }}"
                                    data-test="rich-text-{{ $button['command'] }}"
                                >
                                    @isset($button['icon'])
                                        <flux:icon :name="$button['icon']" variant="micro" class="size-4" />
                                    @else
                                        <span class="text-base leading-none font-semibold" aria-hidden="true">{{ $button['text'] }}</span>
                                    @endisset
                                </button>
                            </flux:tooltip>
                        @endforeach
                    </div>
                @endforeach

                <span class="mx-1 h-5 w-px bg-zinc-200 dark:bg-white/10" aria-hidden="true"></span>
                <div class="flex items-center gap-0.5">
                    <flux:tooltip :content="__('content.editor.link')">
                        <button
                            type="button"
                            x-on:mousedown.prevent
                            x-on:click="openLink()"
                            x-bind:class="(active.link || linkOpen) && '{{ $activeClass }}'"
                            x-bind:aria-pressed="active.link ? 'true' : 'false'"
                            x-bind:aria-expanded="linkOpen ? 'true' : 'false'"
                            x-bind:disabled="!ready"
                            aria-label="{{ __('content.editor.link') }}"
                            class="{{ $buttonClass }}"
                            data-test="rich-text-link"
                        >
                            <flux:icon name="link" variant="micro" class="size-4" />
                        </button>
                    </flux:tooltip>
                    @if ($canPickImages)
                        <flux:tooltip :content="__('content.editor.image')">
                            <button
                                type="button"
                                x-on:click="openImagePicker()"
                                x-bind:disabled="!ready"
                                aria-label="{{ __('content.editor.image') }}"
                                class="{{ $buttonClass }}"
                                data-test="rich-text-image"
                            >
                                <flux:icon name="photo" variant="micro" class="size-4" />
                            </button>
                        </flux:tooltip>
                    @endif
                </div>

                <div class="ms-auto flex items-center gap-0.5">
                    @foreach (['undo' => 'arrow-uturn-left', 'redo' => 'arrow-uturn-right'] as $command => $icon)
                        <flux:tooltip :content="__('content.editor.'.$command)">
                            <button
                                type="button"
                                x-on:mousedown.prevent
                                x-on:click="command('{{ $command }}')"
                                x-bind:disabled="!ready || !{{ $command === 'undo' ? 'canUndo' : 'canRedo' }}"
                                aria-label="{{ __('content.editor.'.$command) }}"
                                class="{{ $buttonClass }}"
                                data-test="rich-text-{{ $command }}"
                            >
                                <flux:icon :name="$icon" variant="micro" class="size-4" />
                            </button>
                        </flux:tooltip>
                    @endforeach
                </div>
            </div>

            {{-- Not a <form>: the editor sits inside the page's form, and forms can't be nested. --}}
            <div
                x-show="linkOpen"
                x-cloak
                role="group"
                aria-label="{{ __('content.editor.link') }}"
                x-on:keydown.escape.prevent.stop="closeLink()"
                class="flex flex-wrap items-end gap-3 border-b border-zinc-200 bg-zinc-50/60 px-3 py-3 dark:border-white/10 dark:bg-white/[0.03]"
                data-test="rich-text-link-form"
            >
                <div class="grid min-w-0 flex-1 basis-64 gap-1.5">
                    <flux:label for="{{ $key }}-href">{{ __('content.editor.link_form.address') }}</flux:label>
                    <flux:input
                        id="{{ $key }}-href"
                        x-ref="linkInput"
                        x-model="linkHref"
                        x-on:input="linkInvalid = false"
                        x-on:keydown.enter.prevent="applyLink()"
                        size="sm"
                        :placeholder="__('content.editor.link_form.placeholder')"
                        autocomplete="off"
                    />
                    <p x-show="linkInvalid" x-cloak class="text-sm text-red-600 dark:text-red-400" role="alert">{{ __('content.editor.link_form.invalid') }}</p>
                </div>
                <label class="inline-flex h-8 items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                    <input type="checkbox" x-model="linkNewTab" x-on:keydown.enter.prevent="applyLink()" class="size-4 rounded border-zinc-300 accent-zinc-800 dark:accent-white" />
                    {{ __('content.editor.link_form.new_tab') }}
                </label>
                <div class="flex items-center gap-2">
                    <flux:button type="button" size="sm" variant="ghost" x-on:click="closeLink()">{{ __('content.editor.link_form.cancel') }}</flux:button>
                    <flux:button type="button" size="sm" variant="ghost" x-show="active.link" x-on:click="removeLink()">{{ __('content.editor.link_form.remove') }}</flux:button>
                    <flux:button type="button" size="sm" variant="primary" x-on:click="applyLink()">{{ __('content.editor.link_form.apply') }}</flux:button>
                </div>
            </div>

            <div x-show="imageSelected" x-cloak class="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-b border-zinc-200 bg-zinc-50/60 px-3 py-2 dark:border-white/10 dark:bg-white/[0.03]" data-test="rich-text-alt">
                <flux:label for="{{ $key }}-alt" class="shrink-0">{{ __('content.editor.image_alt.label') }}</flux:label>
                <div class="min-w-0 flex-1 basis-56">
                    <flux:input
                        id="{{ $key }}-alt"
                        x-model="imageAlt"
                        x-on:input="updateAlt()"
                        x-on:keydown.enter.prevent
                        size="sm"
                        :placeholder="__('content.editor.image_alt.placeholder')"
                    />
                </div>
            </div>
        @endunless

        <div x-show="!ready && !failed" class="min-h-64 px-4 py-3" aria-busy="true">
            <span class="sr-only">{{ __('content.editor.loading') }}</span>
            <flux:skeleton.group animate="shimmer" class="space-y-3">
                <flux:skeleton.line class="w-3/4" />
                <flux:skeleton.line />
                <flux:skeleton.line class="w-5/6" />
                <flux:skeleton.line class="w-2/3" />
            </flux:skeleton.group>
        </div>

        <p x-show="failed" x-cloak class="min-h-64 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert">{{ __('content.editor.failed') }}</p>

        <div x-ref="content" x-show="ready" x-cloak></div>
    </div>

    @if ($model !== '')
        <flux:error :name="$model" />
    @endif
</div>
