<?php

namespace Hata\Support\Content;

use Hata\Contracts\AdminUser;
use Hata\Enums\SystemRole;
use Hata\Hata;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The users who may be a record's author: those who may create the type (directly or through a role), and
 * super admins.
 *
 * The super-admin check by role name mirrors `Gate::before`, the one place the project checks a role name.
 */
final class EntryAuthors
{
    /**
     * @return Builder<Authenticatable&AdminUser>
     */
    public static function query(ContentType $type): Builder
    {
        $permission = $type->permission('create');
        $named = fn (Builder $query) => $query->where('name', $permission);

        return Hata::userModel()::query()->where(fn (Builder $query) => $query
            ->whereHas('permissions', $named)
            ->orWhereHas('roles', fn (Builder $roles) => $roles
                ->where('name', SystemRole::SuperAdmin->value)
                ->orWhereHas('permissions', $named)));
    }

    /**
     * @return array<int, string> id => name, by name
     */
    public static function options(ContentType $type): array
    {
        return self::query($type)->orderBy('name')->pluck('name', 'id')->all();
    }
}
