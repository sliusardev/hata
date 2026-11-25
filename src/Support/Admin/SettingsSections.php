<?php

namespace Hata\Support\Admin;

use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Livewire\Livewire;

/**
 * The registry of the sections of the site settings page.
 *
 * Hata's own sections and a module's register in a provider's `boot()`; nothing is checked until the page asks, so
 * a component that Livewire cannot resolve yet never breaks the boot, and no query is made. A mistake in a
 * registration (a key that is not lowercase words joined by hyphens, a component Livewire cannot resolve) throws a
 * LogicException naming the section in the local and testing environments; elsewhere the section is skipped and a
 * warning is logged once per process. Bound as a singleton.
 *
 * @extends Registry<SettingsSection>
 */
final class SettingsSections extends Registry
{
    /**
     * Keys of the page's former tabs and the sections that took them over (old links, bookmarks, toasts).
     */
    public const array ALIASES = ['general' => 'site', 'logo' => 'appearance', 'colors' => 'appearance'];

    public function name(): string
    {
        return 'settings section';
    }

    public function describe(Entry $entry): string
    {
        return "component [{$entry->component}]";
    }

    /**
     * The sections this user may open, by order (ties keep the order they were added in).
     *
     * @return list<SettingsSection>
     */
    public function for(AdminUser $user): array
    {
        return $this->entriesFor($user);
    }

    /**
     * Whether the user may open any section: permissions only, stopping at the first allowed one. The sidebar asks it
     * on every admin request, so it never resolves a component; a broken registration is reported by `for()`, which
     * only the settings page calls.
     */
    public function visibleTo(AdminUser $user): bool
    {
        foreach ($this->resolve()['entries'] as $section) {
            if ($this->allows($user, $section)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The section a `?tab=` opens for this user: the key (an old key through ALIASES, unless a section took it) when
     * the user may open it, else the user's first section; null when the user has none. Resolved from `for()`, so a
     * broken registration never opens.
     */
    public function open(AdminUser $user, string $key): ?SettingsSection
    {
        return $this->pick($this->for($user), $key);
    }

    /**
     * The section `open()` would choose from a list `for()` already returned, so a page that needs the list too
     * resolves the components once.
     *
     * @param  list<SettingsSection>  $sections
     */
    public function pick(array $sections, string $key): ?SettingsSection
    {
        if (! $this->has($key)) {
            $key = self::ALIASES[$key] ?? $key;
        }

        foreach ($sections as $section) {
            if ($section->key === $key) {
                return $section;
            }
        }

        return $sections[0] ?? null;
    }

    protected function keyPattern(): string
    {
        return '/^[a-z0-9]+(-[a-z0-9]+)*\z/';
    }

    protected function keyProblem(): string
    {
        return 'its key is not lowercase letters and digits joined by hyphens';
    }

    protected function validate(Entry $entry): ?string
    {
        return Livewire::exists($entry->component)
            ? null
            : "its component [{$entry->component}] cannot be resolved by Livewire";
    }
}
