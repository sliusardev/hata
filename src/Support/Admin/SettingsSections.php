<?php

namespace Hata\Support\Admin;

use Closure;
use Hata\Contracts\AdminUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use LogicException;

/**
 * The registry of the sections of the site settings page.
 *
 * Hata's own sections and a module's register in a provider's `boot()`; nothing is checked until the page asks, so
 * a component that Livewire cannot resolve yet never breaks the boot, and no query is made. A mistake in a
 * registration (a key that is not lowercase words joined by hyphens, a component Livewire cannot resolve) throws a
 * LogicException naming the section in the local and testing environments; elsewhere
 * the section is skipped and a warning is logged once per process. Bound as a singleton.
 */
final class SettingsSections
{
    private const KEY_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @var array<string, SettingsSection>
     */
    private array $sections = [];

    /**
     * The registration mistakes this process has logged already.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    /**
     * Adds a section; one with the same key replaces the earlier one (and keeps its place among equal orders).
     */
    public function add(SettingsSection $section): static
    {
        $this->sections[$section->key] = $section;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->sections[$key]);

        return $this;
    }

    /**
     * The sections this user may open, by order (ties keep the order they were added in).
     *
     * @return list<SettingsSection>
     */
    public function for(AdminUser $user): array
    {
        $position = array_flip(array_keys($this->sections));
        $sections = $this->sections;
        uasort($sections, fn (SettingsSection $a, SettingsSection $b): int => [$a->order, $position[$a->key]] <=> [$b->order, $position[$b->key]]);

        return array_values(array_filter(
            $sections,
            fn (SettingsSection $section): bool => $this->isRegistrable($section) && $this->allows($user, $section),
        ));
    }

    public function visibleTo(AdminUser $user): bool
    {
        return $this->for($user) !== [];
    }

    public function find(string $key): ?SettingsSection
    {
        return $this->sections[$key] ?? null;
    }

    public function allows(AdminUser $user, SettingsSection $section): bool
    {
        if ($section->permission === null) {
            return true;
        }

        if ($section->permission instanceof Closure) {
            return ($section->permission)($user);
        }

        return Gate::forUser($user)->allows($section->permission);
    }

    private function isRegistrable(SettingsSection $section): bool
    {
        if (preg_match(self::KEY_PATTERN, $section->key) !== 1) {
            $this->problem($section, 'its key is not lowercase letters and digits joined by hyphens');

            return false;
        }

        if (! Livewire::exists($section->component)) {
            $this->problem($section, "its component [{$section->component}] cannot be resolved by Livewire");

            return false;
        }

        return true;
    }

    /**
     * A registration mistake: thrown where a developer sees it (local and testing), skipped elsewhere and logged once
     * per process, so a production site still renders its settings page.
     */
    private function problem(SettingsSection $section, string $problem): void
    {
        $message = "The settings section [{$section->key}] is left out: {$problem}.";

        if (app()->environment('local', 'testing')) {
            throw new LogicException($message);
        }

        if (isset(self::$warned[$message])) {
            return;
        }

        self::$warned[$message] = true;

        Log::warning($message);
    }
}
