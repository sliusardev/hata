<?php

use Hata\Actions\Settings\RemoveLogo;
use Hata\Actions\Settings\SetLogo;
use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Livewire\Admin\Settings\SettingsSectionPage;
use Hata\Models\Media;
use Hata\Support\ColorScheme\ColorScheme;
use Hata\Support\Settings\SiteLogo;
use Hata\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

new class extends SettingsSectionPage {
    /**
     * @var list<string>
     */
    private const array LOGO_FIELDS = ['logo_light', 'logo_dark'];

    public static function key(): string
    {
        return 'appearance';
    }

    protected function fields(): array
    {
        return ['logo_light', 'logo_dark', 'color_scheme', 'color_accent', 'color_gray', 'color_tint_sidebar'];
    }

    /**
     * A logo id is checked for real by SetLogo (the file, its format, media.view); here only its shape.
     */
    protected function rules(): array
    {
        return [
            'values.logo_light' => ['nullable', 'integer', 'min:1'],
            'values.logo_dark' => ['nullable', 'integer', 'min:1'],
            'values.color_scheme' => ['required', Rule::in([...array_keys(ColorScheme::PRESETS), ColorScheme::CUSTOM])],
            'values.color_accent' => ['nullable', 'required_if:values.color_scheme,'.ColorScheme::CUSTOM, 'regex:/^#[0-9a-fA-F]{6}$/'],
            'values.color_gray' => ['required', Rule::in(array_keys(ColorScheme::GRAYS))],
            'values.color_tint_sidebar' => ['boolean'],
        ];
    }

    /**
     * The logos are the files chosen (a setting whose file is gone shows an empty picker), the colors the values in effect.
     */
    protected function load(): array
    {
        $values = parent::load();

        $values['logo_light'] = SiteLogo::own('light')?->id;
        $values['logo_dark'] = SiteLogo::own('dark')?->id;
        $values['color_scheme'] = (string) config('hata.admin.colors.scheme');
        $values['color_accent'] = ColorScheme::isHexColor(config('hata.admin.colors.accent')) ? (string) config('hata.admin.colors.accent') : '#2563eb';
        $values['color_gray'] = (string) config('hata.admin.colors.gray');
        $values['color_tint_sidebar'] = (bool) config('hata.admin.colors.tint_sidebar');

        return $values;
    }

    /**
     * Choosing the custom scheme stores its company color and gray with it, so the scheme never half-follows the config.
     */
    protected function changed(): array
    {
        $changed = parent::changed();

        if (in_array('color_scheme', $changed, true) && ($this->values['color_scheme'] ?? '') === ColorScheme::CUSTOM) {
            $changed = array_values(array_unique([...$changed, 'color_accent', 'color_gray']));
        }

        return $changed;
    }

    protected function prepare(array $validated): array
    {
        foreach (self::LOGO_FIELDS as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = filled($validated[$field]) ? (int) $validated[$field] : null;
            }
        }

        if (array_key_exists('color_accent', $validated)) {
            $validated['color_accent'] = filled($validated['color_accent']) ? strtolower((string) $validated['color_accent']) : null;
        }

        if (array_key_exists('color_tint_sidebar', $validated)) {
            $validated['color_tint_sidebar'] = (bool) $validated['color_tint_sidebar'];
        }

        $logos = array_intersect_key($validated, array_flip(self::LOGO_FIELDS));

        return [...$logos, ...parent::prepare(array_diff_key($validated, $logos))];
    }

    /**
     * Logos and colors are stored together or not at all: a refused logo rolls the colors back, and the settings
     * cache and config refresh only after the whole transaction commits.
     */
    protected function persist(array $changed): void
    {
        $actor = Auth::user();

        DB::transaction(function () use ($actor, $changed): void {
            foreach (self::LOGO_FIELDS as $field) {
                if (! array_key_exists($field, $changed)) {
                    continue;
                }

                $variant = substr($field, 5);

                if ($changed[$field] === null) {
                    app(RemoveLogo::class)->handle($actor, $variant);

                    continue;
                }

                try {
                    app(SetLogo::class)->handle($actor, $variant, $changed[$field]);
                } catch (ValidationException|AuthorizationException) {
                    throw ValidationException::withMessages(["values.{$field}" => __('hata::media.picker.invalid')]);
                }
            }

            $colors = array_diff_key($changed, array_flip(self::LOGO_FIELDS));

            if ($colors !== []) {
                app(UpdateSiteSettings::class)->handle($actor, $colors);
            }
        });
    }

    /**
     * The "logo file is missing" button: stop using the logo at once, without Save.
     */
    public function removeLogo(string $variant): void
    {
        $this->authorizeSection();

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            return;
        }

        app(RemoveLogo::class)->handle(Auth::user(), $variant);

        Toast::success(__('hata::site_settings.logo.removed'));

        $this->redirectRoute('admin.settings.edit', ['tab' => static::key()]);
    }

    /**
     * The file chosen for each variant (no fallback to the other one), for the previews.
     *
     * @return array<string, Media|null>
     */
    #[Computed]
    public function chosenLogos(): array
    {
        return ['light' => SiteLogo::own('light'), 'dark' => SiteLogo::own('dark')];
    }

    /**
     * Whether the variant's setting holds a media id whose file is gone (row deleted, file missing or not a logo):
     * the picker shows no choice then, so the slot offers its own "Remove".
     */
    public function logoIsMissing(string $variant): bool
    {
        return is_int(config("hata.admin.logo.{$variant}")) && $this->chosenLogos[$variant] === null;
    }

    /**
     * The scheme as currently chosen in the form, applied to the page before it is saved.
     */
    #[Computed]
    public function previewCss(): string
    {
        return $this->schemeFromForm()->css(includeStock: true);
    }

    /**
     * Swatches of every option: light accent, dark accent and the light sidebar.
     *
     * @return array<string, array{accent: string, dark: string, sidebar: string}>
     */
    #[Computed]
    public function schemeSwatches(): array
    {
        $swatches = [];

        foreach ([...array_keys(ColorScheme::PRESETS), ColorScheme::CUSTOM] as $key) {
            $scheme = $key === ColorScheme::CUSTOM
                ? ColorScheme::resolve($key, (string) ($this->values['color_accent'] ?? ''), (string) ($this->values['color_gray'] ?? ''), true)
                : ColorScheme::resolve($key, null, null, true);
            $variables = $scheme->variables(includeStock: true);

            $swatches[$key] = [
                'accent' => $variables['light']['--color-accent'],
                'dark' => $variables['dark']['--color-sidebar'],
                'sidebar' => $variables['light']['--color-sidebar'],
            ];
        }

        return $swatches;
    }

    private function schemeFromForm(): ColorScheme
    {
        return ColorScheme::resolve(
            (string) ($this->values['color_scheme'] ?? ''),
            (string) ($this->values['color_accent'] ?? ''),
            (string) ($this->values['color_gray'] ?? ''),
            (bool) ($this->values['color_tint_sidebar'] ?? false),
        );
    }
}; ?>

<x-hata::admin.settings-section>
    {{-- Live preview: overrides the saved scheme from <head> while this section is open. --}}
    <style>{!! $this->previewCss !!}</style>

    <div class="space-y-6">
        <flux:heading size="lg" level="3">{{ __('hata::site_settings.logo.heading') }}</flux:heading>
            <flux:text>{{ __('hata::site_settings.logo.description') }}</flux:text>

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach (['light' => 'values.logo_light', 'dark' => 'values.logo_dark'] as $variant => $property)
                    <div class="space-y-3" wire:key="logo-{{ $variant }}">
                        <flux:heading>{{ __("hata::site_settings.logo.{$variant}") }}</flux:heading>

                        <div @class([
                            'flex h-24 items-center justify-center rounded-lg border px-4',
                            'border-zinc-200 bg-zinc-50' => $variant === 'light',
                            'border-zinc-700 bg-zinc-900' => $variant === 'dark',
                        ])>
                            @if ($this->chosenLogos[$variant])
                                <img src="{{ $this->chosenLogos[$variant]->url() }}" alt="" class="h-12 w-auto max-w-full object-contain" data-test="logo-preview-{{ $variant }}" />
                            @else
                                <div class="text-center">
                                    @if (SiteLogo::isCustom())
                                        <img src="{{ SiteLogo::url($variant) }}" alt="" class="mx-auto h-12 w-auto max-w-full object-contain opacity-60" />
                                    @else
                                        <span @class([
                                            'mx-auto flex size-12 items-center justify-center rounded-md',
                                            'bg-neutral-800 text-white' => $variant === 'light',
                                            'bg-white text-neutral-800' => $variant === 'dark',
                                        ])>
                                            <x-hata::app-logo-icon class="size-[72%]" />
                                        </span>
                                    @endif
                                    <flux:text class="mt-1 text-xs {{ $variant === 'dark' ? 'text-zinc-400!' : '' }}">
                                        {{ SiteLogo::isCustom() ? __('hata::site_settings.logo.uses_other') : __('hata::site_settings.logo.default') }}
                                    </flux:text>
                                </div>
                            @endif
                        </div>

                        @if ($this->logoIsMissing($variant))
                            <div class="flex items-center gap-3" data-test="logo-missing-{{ $variant }}">
                                <flux:text size="sm" class="flex-1">{{ __('hata::site_settings.logo.missing') }}</flux:text>
                                <flux:button size="sm" variant="ghost" wire:click="removeLogo('{{ $variant }}')">{{ __('hata::site_settings.logo.remove_missing') }}</flux:button>
                            </div>
                        @endif

                        <livewire:hata::media-picker
                            wire:model.live="{{ $property }}"
                            accept="image"
                            :extensions="SiteLogo::EXTENSIONS"
                            :label="__('hata::site_settings.logo.'.$variant)"
                            :key="'logo-picker-'.$variant"
                        />
                        <flux:error :name="$property" />
                    </div>
                @endforeach
            </div>

            <flux:text class="text-xs">{{ __('hata::site_settings.logo.requirements') }}</flux:text>

    </div>

    <div class="space-y-6">
        <flux:heading size="lg" level="3">{{ __('hata::site_settings.colors.heading') }}</flux:heading>
            <flux:text>{{ __('hata::site_settings.colors.description') }}</flux:text>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" role="radiogroup" aria-label="{{ __('hata::site_settings.colors.heading') }}">
                @foreach ($this->schemeSwatches as $key => $swatch)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ ($values['color_scheme'] ?? null) === $key ? 'true' : 'false' }}"
                        wire:click="$set('values.color_scheme', '{{ $key }}')"
                        wire:key="scheme-{{ $key }}"
                        data-test="color-scheme-{{ $key }}"
                        @class([
                            'flex cursor-pointer flex-col gap-2 rounded-lg border p-3 text-start transition',
                            'border-accent ring-2 ring-accent' => ($values['color_scheme'] ?? null) === $key,
                            'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600' => ($values['color_scheme'] ?? null) !== $key,
                        ])
                    >
                        <span class="flex items-center gap-1">
                            <span class="size-5 rounded-full border border-black/10" style="background: {{ $swatch['accent'] }}"></span>
                            <span class="size-5 rounded-full border border-black/10" style="background: {{ $swatch['sidebar'] }}"></span>
                            <span class="size-5 rounded-full border border-white/10" style="background: {{ $swatch['dark'] }}"></span>
                        </span>
                        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ __("hata::site_settings.colors.schemes.{$key}") }}</span>
                    </button>
                @endforeach
            </div>
            <flux:error name="values.color_scheme" />

            @if (($values['color_scheme'] ?? null) === Hata\Support\ColorScheme\ColorScheme::CUSTOM)
                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('hata::site_settings.colors.accent') }}</flux:label>
                        <flux:description>{{ __('hata::site_settings.colors.accent_description') }}</flux:description>
                        <div class="flex items-center gap-2">
                            <input
                                type="color"
                                wire:model.live.debounce.200ms="values.color_accent"
                                aria-label="{{ __('hata::site_settings.colors.accent') }}"
                                class="h-10 w-14 shrink-0 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1 dark:border-zinc-600 dark:bg-zinc-700"
                            />
                            <flux:input wire:model.live.blur="values.color_accent" placeholder="#2563eb" maxlength="7" class="font-mono" />
                        </div>
                        <flux:error name="values.color_accent" />
                    </flux:field>

                    <flux:select wire:model.live="values.color_gray" :label="__('hata::site_settings.colors.gray')" :description="__('hata::site_settings.colors.gray_description')">
                        @foreach (array_keys(Hata\Support\ColorScheme\ColorScheme::GRAYS) as $gray)
                            <flux:select.option :value="$gray" wire:key="gray-{{ $gray }}">{{ __("hata::site_settings.colors.grays.{$gray}") }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            @unless (($values['color_scheme'] ?? null) === 'neutral')
                <flux:switch wire:model.live="values.color_tint_sidebar" :label="__('hata::site_settings.colors.tint_sidebar')" :description="__('hata::site_settings.colors.tint_sidebar_description')" align="left" />
            @endunless

            <flux:callout icon="eye" variant="secondary" :heading="__('hata::site_settings.colors.preview_hint')" />
    </div>
</x-hata::admin.settings-section>
