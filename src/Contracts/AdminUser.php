<?php

namespace Hata\Contracts;

use Hata\Models\Role;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Spatie\Permission\Models\Permission;

/**
 * The host's user model, as Hata uses it. The host's `User` implements it and uses `IsAdminUser`, which provides
 * every method here; Hata names this interface wherever it takes or returns a user and resolves the class through
 * `Hata::userModel()`, never by the host's class name.
 *
 * The `@method` lines are the spatie/laravel-permission methods (from `HasRoles`, used by `IsAdminUser`) that Hata
 * calls on a user, declared here for static analysis only so that their real signatures stay spatie's.
 *
 * @phpstan-require-extends Authenticatable
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $locale
 * @property string|null $search_index
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Permission> $permissions
 *
 * @method BelongsToMany<Role, Authenticatable&AdminUser> roles()
 * @method bool hasRole(mixed $roles, ?string $guard = null)
 * @method $this assignRole(mixed ...$roles)
 * @method $this syncRoles(mixed ...$roles)
 * @method $this givePermissionTo(mixed ...$permissions)
 * @method bool hasPermissionTo(mixed $permission, ?string $guardName = null)
 * @method \Illuminate\Support\Collection<int, Permission> getAllPermissions()
 * @method \Illuminate\Support\Collection<int, string> getRoleNames()
 */
interface AdminUser extends HasLocalePreference, PasskeyUser
{
    /**
     * Whether the user has the super-admin role.
     */
    public function isSuperAdmin(): bool;

    /**
     * The user's initials (at most two letters), shown in the avatar.
     */
    public function initials(): string;
}
