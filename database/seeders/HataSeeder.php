<?php

namespace Hata\Database\Seeders;

use Hata\Actions\Users\CreateSuperAdmin;
use Hata\Hata;
use Hata\Support\Localization\ContentLocales;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class HataSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Safe to run on every deploy: permissions, roles and the initial super admin are
     * only created when missing, and the content languages are stored once.
     */
    public function run(CreateSuperAdmin $createSuperAdmin): void
    {
        Artisan::call('permissions:sync');

        $this->call(RoleSeeder::class);

        $this->storeContentLanguages(app(SiteSettings::class), app(ContentLocales::class), app(LanguageCatalog::class));

        $this->createInitialAdmin($createSuperAdmin);
    }

    /**
     * Store the site's content language list while none is stored, as the interface default of the moment.
     * Without it the default content language follows the interface default, so changing that one in the
     * settings would move the site's content language and answer 404 for published records.
     */
    private function storeContentLanguages(SiteSettings $settings, ContentLocales $locales, LanguageCatalog $catalog): void
    {
        $settings->apply();

        if ($settings->has('content_locales')) {
            return;
        }

        $code = $locales->interfaceDefault();

        if (! $catalog->has($code)) {
            return;
        }

        $settings->put([
            'content_locales' => [['code' => $code, 'active' => true]],
            'content_default_locale' => $code,
        ]);
    }

    /**
     * Create the super admin configured in ADMIN_EMAIL / ADMIN_PASSWORD, for hosting without SSH.
     */
    private function createInitialAdmin(CreateSuperAdmin $createSuperAdmin): void
    {
        /** @var array{name: string|null, email: string|null, password: string|null} $admin */
        $admin = config('hata.admin.initial_admin');

        if (blank($admin['email']) || blank($admin['password'])) {
            return;
        }

        if (Hata::userModel()::query()->where('email', Str::lower($admin['email']))->exists()) {
            return;
        }

        $createSuperAdmin->handle([
            'name' => filled($admin['name']) ? $admin['name'] : 'Administrator',
            'email' => $admin['email'],
            'password' => $admin['password'],
        ]);
    }
}
