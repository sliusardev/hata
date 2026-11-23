<?php

namespace App\Models;

use Hata\Contracts\AdminUser;
use Hata\Models\Concerns\IsAdminUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A second host user model on a table of its own (`members`, created by the test that uses it), to prove Hata
 * follows `auth.providers.users.model` rather than a fixed class.
 */
class Member extends Authenticatable implements AdminUser
{
    use IsAdminUser;

    protected $table = 'members';
}
