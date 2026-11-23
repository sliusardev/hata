<?php

namespace App\Models;

use Hata\Contracts\AdminUser;
use Hata\Models\Concerns\IsAdminUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host user model that guards only its key (`$guarded = ['id']`, no fillable): Hata's trait must leave every
 * other column fillable.
 *
 * @property string|null $title
 */
class PartlyGuardedMember extends Authenticatable implements AdminUser
{
    use IsAdminUser;

    protected $table = 'members';

    protected $guarded = ['id'];
}
