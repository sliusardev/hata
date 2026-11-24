@props([
    'heading' => null,
    'description' => null,
    'secrets' => [],
    'save' => true,
])

{{--
    The root of a settings section (a SettingsSectionPage). `data-settings-section` marks it for the page, whose
    leave and switch guards ask its Alpine `isDirty()`: a field differs from the copy it was loaded as, or a secret
    field (`secrets`) has been typed. The cards go in the slot; Save submits the form (`:save="false"` hides it for a
    section whose actions apply at once).
--}}
<form
    wire:submit="save"
    data-settings-section
    x-data="{
        secrets: @js(array_values($secrets)),
        isDirty() {
            const values = $wire.values ?? {};
            const saved = $wire.saved ?? {};
            const text = (value) => value === null || value === undefined ? '' : (typeof value === 'object' ? JSON.stringify(value) : String(value));

            return Object.keys(values).some((field) => this.secrets.includes(field)
                ? text(values[field]).trim() !== ''
                : text(values[field]) !== text(saved[field]));
        },
    }"
    {{ $attributes->class('space-y-6') }}
>
    @if ($heading || $description)
        <div>
            @if ($heading)
                <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
            @endif

            @if ($description)
                <flux:subheading>{{ $description }}</flux:subheading>
            @endif
        </div>
    @endif

    {{ $slot }}

    @if ($save)
        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="settings-section-save">
                {{ __('hata::common.actions.save') }}
            </flux:button>
        </div>
    @endif
</form>
