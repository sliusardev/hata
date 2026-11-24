<?php

use Hata\Actions\Settings\RemoveShareImage;
use Hata\Actions\Settings\SetShareImage;
use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Contracts\AdminUser;
use Hata\Exceptions\ProtectedActionException;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Models\Entry;
use Hata\Models\Media;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Seo\PageMeta;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

new class extends SettingsSectionPage {
    /**
     * The text typed in the home page search. Not a form value, so typing never makes the form dirty.
     */
    public string $homeSearch = '';

    /**
     * Whether the "A page" option is open without a page chosen yet.
     */
    public bool $homeChoosing = false;

    public static function key(): string
    {
        return 'site';
    }

    protected function fields(): array
    {
        return ['site_name', 'timezone', 'home_page', 'site_description', 'og_image'];
    }

    protected function rules(): array
    {
        return [
            'values.site_name' => ['required', 'string', 'max:255'],
            'values.timezone' => ['required', 'timezone:all'],
            'values.home_page' => ['nullable', 'integer', 'min:1'],
            'values.site_description' => ['array'],
            'values.site_description.*' => ['nullable', 'string', 'max:300'],
            'values.og_image' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * A text per active content language (empty when none is stored) and the share image's file when it still exists.
     */
    protected function load(): array
    {
        $values = parent::load();
        $stored = is_array($values['site_description'] ?? null) ? $values['site_description'] : [];

        $values['site_description'] = [];

        foreach (app(ContentLocales::class)->active() as $language) {
            $text = $stored[$language->code] ?? null;
            $values['site_description'][$language->code] = is_string($text) ? $text : '';
        }

        $values['home_page'] = is_numeric($values['home_page'] ?? null) ? (int) $values['home_page'] : null;
        $values['og_image'] = $this->storedShareImage()?->id;

        return $values;
    }

    protected function prepare(array $validated): array
    {
        foreach (['home_page', 'og_image'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = filled($validated[$field]) ? (int) $validated[$field] : null;
            }
        }

        if (array_key_exists('site_description', $validated)) {
            $stored = config('hata.seo.description');
            $merged = is_array($stored) ? $stored : [];

            foreach (app(ContentLocales::class)->active() as $language) {
                unset($merged[$language->code]);

                $text = trim((string) ($validated['site_description'][$language->code] ?? ''));

                if ($text !== '') {
                    $merged[$language->code] = $text;
                }
            }

            $validated['site_description'] = $merged === [] ? null : $merged;
        }

        $special = array_intersect_key($validated, array_flip(['home_page', 'og_image', 'site_description']));

        return [...$special, ...parent::prepare(array_diff_key($validated, $special))];
    }

    /**
     * Everything is stored together or not at all: a refused share image or home page rolls the other fields back.
     */
    protected function persist(array $changed): void
    {
        $actor = Auth::user();

        DB::transaction(function () use ($actor, $changed): void {
            if (array_key_exists('og_image', $changed)) {
                if ($changed['og_image'] === null) {
                    app(RemoveShareImage::class)->handle($actor);
                } else {
                    try {
                        app(SetShareImage::class)->handle($actor, $changed['og_image']);
                    } catch (ValidationException|AuthorizationException) {
                        throw ValidationException::withMessages(['values.og_image' => __('hata::media.picker.invalid')]);
                    }
                }
            }

            $rest = array_diff_key($changed, ['og_image' => true]);

            if ($rest !== []) {
                try {
                    app(UpdateSiteSettings::class)->handle($actor, $rest);
                } catch (ProtectedActionException $exception) {
                    throw ValidationException::withMessages(['values.home_page' => $exception->getMessage()]);
                }
            }
        });
    }

    public function useBuiltInHome(): void
    {
        $this->values['home_page'] = null;
        $this->homeChoosing = false;
        $this->homeSearch = '';
    }

    public function chooseHomePage(): void
    {
        $this->homeChoosing = true;
    }

    public function pickHomePage(int $id): void
    {
        $this->values['home_page'] = $id;
        $this->homeChoosing = false;
        $this->homeSearch = '';
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }

    /**
     * Up to 20 live pages in the default content language that match the search, by path.
     *
     * @return Collection<int, Entry>
     */
    #[Computed]
    public function homeCandidates(): Collection
    {
        return Entry::query()
            ->ofType('pages')
            ->where('locale', app(ContentLocales::class)->defaultCode())
            ->search($this->homeSearch)
            ->orderBy('path')
            ->limit(20)
            ->get(['id', 'title', 'path', 'locale', 'type']);
    }

    /**
     * The page chosen in the form (live ones only), in one query; null when none is chosen or it is gone.
     */
    #[Computed]
    public function homeRecord(): ?Entry
    {
        $id = $this->values['home_page'] ?? null;

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        return Entry::query()->ofType('pages')->whereKey((int) $id)->first(['id', 'title', 'path', 'locale', 'type']);
    }

    /**
     * The share image as stored, when the file still exists and has a shareable format.
     */
    private function storedShareImage(): ?Media
    {
        $id = config('hata.seo.image');
        $media = is_numeric($id) ? Media::query()->find((int) $id) : null;

        return $media !== null && PageMeta::isShareable($media) ? $media : null;
    }

    /**
     * The file in the picker, so the preview follows it before Save. A picked id that differs from the stored one is
     * trusted only when the user may browse the library (as SetShareImage requires).
     */
    #[Computed]
    public function chosenImage(): ?Media
    {
        $id = $this->values['og_image'] ?? null;

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        $user = Auth::user();

        if (! $user instanceof AdminUser || ! $user->can('viewAny', Media::class)) {
            return $this->storedShareImage();
        }

        $media = Media::query()->find((int) $id);

        return $media !== null && PageMeta::isShareable($media) ? $media : null;
    }

    /**
     * @return list<Hata\Support\Localization\Language>
     */
    #[Computed]
    public function languages(): array
    {
        return app(ContentLocales::class)->active();
    }
}; ?>

<x-hata::admin.settings-section>
    <div class="space-y-6">
        <flux:heading size="lg" level="3">{{ __('hata::site_settings.site.heading') }}</flux:heading>

        <flux:input wire:model="values.site_name" :label="__('hata::site_settings.site.site_name')" :description="__('hata::site_settings.site.site_name_description')" required />

        <div class="grid gap-6 sm:grid-cols-2">
            <flux:select wire:model="values.timezone" :label="__('hata::site_settings.site.timezone')" :description="__('hata::site_settings.site.timezone_description')">
                @foreach ($this->timezones as $timezone)
                    <flux:select.option :value="$timezone" wire:key="tz-{{ $timezone }}">{{ str_replace('_', ' ', $timezone) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <div class="space-y-6" data-test="home-card">
        <div>
            <flux:heading size="lg" level="3">{{ __('hata::site_settings.home.heading') }}</flux:heading>
            <flux:text>{{ __('hata::site_settings.home.description') }}</flux:text>
        </div>

        @php($usesPage = ($values['home_page'] ?? null) !== null || $homeChoosing)

        <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('hata::site_settings.home.heading') }}">
            @foreach ([false => 'builtin', true => 'page'] as $isPage => $option)
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $usesPage === $isPage ? 'true' : 'false' }}"
                    wire:click="{{ $isPage ? 'chooseHomePage' : 'useBuiltInHome' }}"
                    wire:key="home-option-{{ $option }}"
                    data-test="home-option-{{ $option }}"
                    @class([
                        'flex cursor-pointer flex-col gap-1 rounded-lg border p-4 text-start transition',
                        'border-accent ring-2 ring-accent' => $usesPage === $isPage,
                        'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => $usesPage !== $isPage,
                    ])
                >
                    <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __("hata::site_settings.home.options.{$option}") }}</span>
                    <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __("hata::site_settings.home.options.{$option}_hint") }}</span>
                </button>
            @endforeach
        </div>

        @if ($usesPage)
            @if (($values['home_page'] ?? null) !== null && ! $homeChoosing)
                <div class="flex items-center gap-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50" data-test="home-chosen">
                    <div class="min-w-0 flex-1">
                        @if ($this->homeRecord)
                            <flux:heading class="truncate">{{ $this->homeRecord->title }}</flux:heading>
                            <flux:text class="truncate font-mono text-xs">/{{ $this->homeRecord->path }}</flux:text>
                        @else
                            <flux:text class="text-amber-600 dark:text-amber-400" data-test="home-missing">{{ __('hata::site_settings.home.missing') }}</flux:text>
                        @endif
                    </div>
                    <flux:button size="sm" wire:click="chooseHomePage">{{ __('hata::site_settings.home.change') }}</flux:button>
                </div>
            @else
                <div class="space-y-3" data-test="home-picker">
                    <flux:input wire:model.live.debounce.300ms="homeSearch" icon="magnifying-glass" :placeholder="__('hata::site_settings.home.search')" clearable />

                    <div class="divide-y divide-zinc-200 overflow-hidden rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                        @forelse ($this->homeCandidates as $candidate)
                            <button type="button" wire:click="pickHomePage({{ $candidate->id }})" wire:key="home-candidate-{{ $candidate->id }}" class="flex w-full cursor-pointer items-center justify-between gap-4 px-4 py-2.5 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <span class="truncate text-sm font-medium text-zinc-800 dark:text-white">{{ $candidate->title }}</span>
                                <span class="shrink-0 font-mono text-xs text-zinc-500">/{{ $candidate->path }}</span>
                            </button>
                        @empty
                            <div class="px-4 py-6 text-center text-sm text-zinc-500">{{ __('hata::site_settings.home.none_found') }}</div>
                        @endforelse
                    </div>
                </div>
            @endif
        @endif

        <flux:error name="values.home_page" />
    </div>

    <div class="space-y-6" data-test="seo-card">
        <div>
            <flux:heading size="lg" level="3">{{ __('hata::site_settings.seo.heading') }}</flux:heading>
            <flux:text>{{ __('hata::site_settings.seo.description') }}</flux:text>
        </div>

        <div class="space-y-5">
            @foreach ($this->languages as $language)
                <flux:field wire:key="description-{{ $language->code }}" x-data="{ count: ($wire.values.site_description?.['{{ $language->code }}'] ?? '').length }">
                    <div class="flex items-center justify-between gap-3">
                        <flux:label>{{ __('hata::site_settings.seo.description_label', ['language' => $language->native]) }}</flux:label>
                        <span class="text-xs tabular-nums" :class="count > 160 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500'"><span x-text="count"></span> / 160</span>
                    </div>
                    <flux:textarea wire:model="values.site_description.{{ $language->code }}" x-on:input="count = $event.target.value.length" rows="2" :placeholder="__('hata::site_settings.seo.description_placeholder')" />
                    <flux:error name="values.site_description.{{ $language->code }}" />
                </flux:field>
            @endforeach
        </div>

        <div class="space-y-3" data-test="share-image">
            <div>
                <flux:heading>{{ __('hata::site_settings.seo.image') }}</flux:heading>
                <flux:text size="sm">{{ __('hata::site_settings.seo.image_description') }}</flux:text>
            </div>

            <div class="flex h-36 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                @if ($this->chosenImage)
                    <img src="{{ $this->chosenImage->url() }}" alt="" class="h-full w-full object-contain" data-test="share-image-preview" />
                @else
                    <flux:text size="sm">{{ __('hata::site_settings.seo.image_none') }}</flux:text>
                @endif
            </div>

            @if ($this->chosenImage && ($this->chosenImage->width < 600 || $this->chosenImage->height < 315))
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('hata::site_settings.seo.image_small')" data-test="share-image-small" />
            @endif

            <livewire:hata::media-picker
                wire:model.live="values.og_image"
                accept="image"
                :extensions="PageMeta::IMAGE_EXTENSIONS"
                :label="__('hata::site_settings.seo.image')"
                key="share-image-picker"
            />
            <flux:error name="values.og_image" />

            <flux:text class="text-xs">{{ __('hata::site_settings.seo.image_hint') }}</flux:text>
        </div>
    </div>
</x-hata::admin.settings-section>
