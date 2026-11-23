<?php

namespace Hata\Models\Concerns;

use Hata\Contracts\AdminUser;
use Hata\Enums\SystemRole;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * What Hata needs on the host's `User`: roles and permissions, the search index, the locale preference, passkeys
 * and two-factor authentication. The host keeps its own model (`class User extends Authenticatable implements
 * AdminUser { use HasFactory, IsAdminUser, Notifiable; }`); the trait adds Hata's fillable, hidden and cast
 * attributes to the host's own and never replaces them.
 *
 * @phpstan-require-extends Authenticatable
 *
 * @phpstan-require-implements AdminUser
 */
trait IsAdminUser
{
    use HasRoles, HasSearchIndex, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Hata's attributes on top of the host's: a cast the host declares itself for the same column wins.
     */
    public function initializeIsAdminUser(): void
    {
        $this->mergeFillable(['name', 'email', 'password', 'locale']);
        $this->mergeHidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'search_index']);
        $this->mergeCasts(array_diff_key([
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ], $this->getCasts()));
    }

    /**
     * The user's preferred locale, used for notifications such as password reset emails.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Whether the user has the super-admin role.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(SystemRole::SuperAdmin->value);
    }

    /**
     * The user's initials (at most two letters), shown in the avatar.
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    protected function searchIndexSource(): string
    {
        return $this->name.' '.$this->email;
    }
}
