<?php

namespace App\Models;

use Hata\Contracts\AdminUser;
use Hata\Models\Concerns\IsAdminUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A host user model that is unguarded (`$guarded = []`): Hata's trait must leave every column fillable.
 *
 * @property string|null $title
 */
class UnguardedMember extends Authenticatable implements AdminUser
{
    use IsAdminUser;

    protected $table = 'members';

    protected $guarded = [];
}
