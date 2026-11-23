<?php

namespace Hata\Models;

use Hata\Enums\SystemRole;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property string $guard_name
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends SpatieRole
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === SystemRole::SuperAdmin->value;
    }

    /**
     * The name shown in the interface: translated for system roles, the label for custom ones.
     */
    public function displayName(): string
    {
        if ($this->is_system) {
            $key = "hata::roles.names.{$this->name}";

            if (__($key) !== $key) {
                return __($key);
            }
        }

        return $this->label ?? $this->name;
    }
}
