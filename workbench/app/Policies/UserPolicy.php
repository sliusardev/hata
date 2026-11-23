<?php

namespace App\Policies;

use App\Models\User;

/**
 * A host policy where Laravel guesses one for `App\Models\User` (`php artisan make:policy` puts it here). It denies
 * everything, to prove Hata's own user policy still decides on the admin users pages.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return false;
    }

    public function view(User $actor, User $user): bool
    {
        return false;
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, User $user): bool
    {
        return false;
    }

    public function delete(User $actor, User $user): bool
    {
        return false;
    }
}
