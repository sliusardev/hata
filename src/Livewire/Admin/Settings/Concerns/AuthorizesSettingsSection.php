<?php

namespace Hata\Livewire\Admin\Settings\Concerns;

/**
 * Livewire calls `<hook><TraitName>` of every trait itself, whatever the component overrides, so a section that
 * overrides `mount()` or `hydrate()` without calling the parent still re-checks its permission on every request.
 *
 * @method void authorizeSection()
 */
trait AuthorizesSettingsSection
{
    public function mountAuthorizesSettingsSection(): void
    {
        $this->authorizeSection();
    }

    public function hydrateAuthorizesSettingsSection(): void
    {
        $this->authorizeSection();
    }
}
