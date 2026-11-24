<?php

namespace Hata\Livewire\Admin\Settings;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\SettingsSections;
use Hata\Support\Settings\SiteSettings;
use Hata\Support\Toast;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One section of the site settings page: a form over a few site setting keys.
 *
 * `$values` holds the section's fields as the form shows them and `$saved` the copy they were loaded as. Save stores
 * only the fields that differ from that copy, so a value that comes from `.env` or a config default and was left
 * alone is never frozen into the database. Secret fields are never loaded (they start empty), are stored only when
 * typed and then always count as a change. The section's permission is checked on mount and again on every later
 * request (`hydrate()`), because a request can name any component; a subclass's own actions are covered by that too.
 *
 * The view wraps its cards in `<x-hata::admin.settings-section>`, which carries the browser's unsaved-changes check.
 */
abstract class SettingsSectionPage extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $saved = [];

    /**
     * The key of the section's `SettingsSection` registration.
     */
    abstract public static function key(): string;

    /**
     * The site setting keys this section edits.
     *
     * @return list<string>
     */
    abstract protected function fields(): array;

    /**
     * Validation rules, keyed `values.<field>`.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    public function mount(): void
    {
        $this->authorizeSection();

        $this->values = $this->load();
        $this->saved = $this->values;
    }

    /**
     * Every request after the first re-checks the permission, whatever action or property update it carries.
     */
    public function hydrate(): void
    {
        $this->authorizeSection();
    }

    public function save(): void
    {
        $this->authorizeSection();

        $validated = $this->validate();
        $changed = $this->changed();

        if ($changed !== []) {
            $values = array_merge(
                Arr::only($this->values, $changed),
                Arr::only(is_array($validated['values'] ?? null) ? $validated['values'] : [], $changed),
            );

            $this->persist($this->prepare($values));
        }

        // Mark the form clean, so the leave-page warning does not fire on the redirect below.
        foreach ($this->secretFields() as $field) {
            $this->values[$field] = '';
        }

        $this->saved = $this->values;

        Toast::success(__('hata::site_settings.messages.saved'));

        // A full reload, so the layout picks up what changed (the site name, the colors).
        $this->redirectRoute('admin.settings.edit', ['tab' => static::key()]);
    }

    /**
     * The values in effect: a key over a config path from that config (stored settings are already applied over it),
     * a stored-only key from its stored value. Secrets start empty.
     *
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        $settings = app(SiteSettings::class);
        $secrets = $this->secretFields();
        $values = [];

        foreach ($this->fields() as $field) {
            if (in_array($field, $secrets, true)) {
                $values[$field] = '';

                continue;
            }

            $definition = $settings->definition($field);

            $values[$field] = $definition?->config !== null
                ? config($definition->config)
                : $settings->value($field);
        }

        return $values;
    }

    /**
     * The fields that are never loaded and are stored only when typed.
     *
     * @return list<string>
     */
    protected function secretFields(): array
    {
        return [];
    }

    /**
     * Turn the changed, validated values into what is stored. A blank value becomes null, which removes the setting
     * so the config value takes over again; a subclass casts its own fields.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function prepare(array $validated): array
    {
        return array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' || $value === null ? null : $value, $validated);
    }

    /**
     * Store the prepared values. Hata's own sections override this to call their actions.
     *
     * @param  array<string, mixed>  $changed
     */
    protected function persist(array $changed): void
    {
        app(SiteSettings::class)->put($changed);
    }

    /**
     * @throws AuthorizationException when the section is not registered or the user may not open it
     */
    protected function authorizeSection(): void
    {
        $user = auth()->user();
        $sections = app(SettingsSections::class);
        $section = $sections->find(static::key());

        if (! $user instanceof AdminUser || $section === null || ! $sections->allows($user, $section)) {
            throw new AuthorizationException;
        }
    }

    /**
     * The fields whose value differs from what was loaded, plus every typed secret (blank spaces are not typed, so
     * they never remove a stored secret).
     *
     * @return list<string>
     */
    protected function changed(): array
    {
        $secrets = $this->secretFields();

        return array_values(array_filter($this->fields(), fn (string $field): bool => in_array($field, $secrets, true)
            ? trim($this->normalize($this->values[$field] ?? null)) !== ''
            : $this->normalize($this->values[$field] ?? null) !== $this->normalize($this->saved[$field] ?? null)));
    }

    /**
     * A value as the browser's dirty check compares it, so the server and the warning agree on what changed.
     */
    private function normalize(mixed $value): string
    {
        return match (true) {
            is_array($value) => (string) json_encode($value),
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
