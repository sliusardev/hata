<?php

namespace Hata\Database\Seeders;

use App\Models\User;
use Hata\Actions\Users\CreateSuperAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Safe to run on every deploy: permissions, roles and the initial super admin are
     * only created when missing.
     */
    public function run(CreateSuperAdmin $createSuperAdmin): void
    {
        Artisan::call('permissions:sync');

        $this->call(RoleSeeder::class);

        $this->createInitialAdmin($createSuperAdmin);
    }

    /**
     * Create the super admin configured in ADMIN_EMAIL / ADMIN_PASSWORD, for hosting without SSH.
     */
    private function createInitialAdmin(CreateSuperAdmin $createSuperAdmin): void
    {
        /** @var array{name: string|null, email: string|null, password: string|null} $admin */
        $admin = config('admin.initial_admin');

        if (blank($admin['email']) || blank($admin['password'])) {
            return;
        }

        if (User::query()->where('email', Str::lower($admin['email']))->exists()) {
            return;
        }

        $createSuperAdmin->handle([
            'name' => filled($admin['name']) ? $admin['name'] : 'Administrator',
            'email' => $admin['email'],
            'password' => $admin['password'],
        ]);
    }
}
