<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Hata\Contracts\AdminUser;
use Hata\Models\Concerns\IsAdminUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The workbench host's user: the shape `hata:install` writes, plus a column of the host's own (`nickname`, which
 * exists only where a test adds it) to prove Hata's trait keeps the host's fillable and casts.
 *
 * @property string|null $nickname
 */
#[Fillable(['nickname'])]
class User extends Authenticatable implements AdminUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, IsAdminUser, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nickname' => 'string',
        ];
    }
}
