{{--
    Site settings → Content types: every registered type with a switch. Switching applies at once through the page's
    actions (turning one off asks first); the tab has no Save button. `$this->contentTypeRows` gives each type, whether
    it is on and how many records it holds (one grouped query).
--}}
<div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-white/10 dark:bg-zinc-900" data-test="content-types">
    <div class="border-b border-zinc-200 p-5 dark:border-white/10">
        <flux:heading level="2">{{ __('site_settings.content.list_heading') }}</flux:heading>
        <flux:text size="sm" class="mt-1 max-w-prose">{{ __('site_settings.content.list_description') }}</flux:text>
    </div>

    <ul class="divide-y divide-zinc-200 dark:divide-white/10">
        @foreach ($this->contentTypeRows as $row)
            @php($type = $row['type'])
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5" wire:key="content-type-{{ $type->key() }}" data-test="content-type-{{ $type->key() }}" data-active="{{ $row['active'] ? 'true' : 'false' }}">
                <span @class(['flex size-10 shrink-0 items-center justify-center rounded-lg', 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200' => $row['active'], 'bg-zinc-50 text-zinc-300 dark:bg-white/5 dark:text-zinc-600' => ! $row['active']])>
                    <flux:icon :name="$type->icon()" variant="outline" class="size-5" />
                </span>

                <div class="min-w-0 flex-1 basis-48">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class(['truncate font-medium', 'text-zinc-800 dark:text-white' => $row['active'], 'text-zinc-500 dark:text-zinc-400' => ! $row['active']])>{{ $type->pluralLabel() }}</span>
                        @if ($type->required())
                            <flux:badge size="sm" color="amber">{{ __('site_settings.content.badges.required') }}</flux:badge>
                        @elseif (! $row['active'])
                            <flux:badge size="sm" color="zinc">{{ __('site_settings.content.badges.off') }}</flux:badge>
                        @endif
                    </div>
                    <flux:text size="sm">{{ $type->description() }}</flux:text>
                </div>

                <div class="w-32 text-sm text-zinc-500 tabular-nums max-sm:hidden dark:text-zinc-400">{{ trans_choice('site_settings.content.records', $row['count']) }}</div>

                <div class="shrink-0">
                    @if ($type->required())
                        <flux:switch checked disabled :aria-label="__('site_settings.content.switch', ['type' => $type->pluralLabel()])" />
                    @else
                        {{-- Not `wire:model`: the switch shows the stored state and asks the server to change it. --}}
                        <flux:switch
                            :checked="$row['active']"
                            x-on:click.prevent="$wire.toggleContentType(@js($type->key()))"
                            :aria-label="__('site_settings.content.switch', ['type' => $type->pluralLabel()])"
                            data-test="content-type-switch-{{ $type->key() }}"
                        />
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
