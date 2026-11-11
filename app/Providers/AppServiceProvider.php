<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Settings\SiteSettings;
use App\Support\Translation\VendorTranslations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            PermissionRegistry::class,
            fn (): PermissionRegistry => (new PermissionRegistry)->registerMany(config('permissions.groups', [])),
        );

        $this->app->singleton(SiteSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(SiteSettings::class)->apply();
        $this->configureQueue();
        $this->configureMedia();
        $this->configureDefaults();
        $this->configureTranslations();
        $this->configureAuthorization();
    }

    /**
     * Re-apply the site settings before every queued job. A worker boots once, but the settings live in the database
     * and change while it runs; `stored()` reads a cache that saving clears, and `apply()` first restores the config
     * values of settings applied earlier, so a removed setting falls back to `.env` here too.
     */
    protected function configureQueue(): void
    {
        Queue::before(function (): void {
            $this->app->make(SiteSettings::class)->apply();
            Mail::forgetMailers();
        });
    }

    /**
     * Pick the image driver and let Livewire accept the largest media file.
     */
    protected function configureMedia(): void
    {
        config([
            'images.default' => extension_loaded('imagick') ? 'imagick' : 'gd',
            'livewire.temporary_file_upload.rules' => [
                'required',
                'file',
                'max:'.(max((int) config('media.limits.image_mb'), (int) config('media.limits.document_mb')) * 1024),
            ],
        ]);
    }

    /**
     * Let super admins pass every authorization check.
     *
     * Returning null for everyone else lets the regular permission checks decide.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(
            fn (User $user): ?bool => $user->isSuperAdmin() ? true : null,
        );
    }

    /**
     * Translate literal strings that vendor packages pass to __().
     */
    protected function configureTranslations(): void
    {
        Lang::handleMissingKeysUsing(
            fn (string $key, array $replace, ?string $locale): ?string => VendorTranslations::translate($key, $replace, $locale),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
