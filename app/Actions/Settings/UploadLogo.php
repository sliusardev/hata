<?php

namespace App\Actions\Settings;

use App\Models\User;
use App\Support\Settings\SiteLogo;
use App\Support\Settings\SiteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

class UploadLogo
{
    public function __construct(private SiteSettings $settings) {}

    /**
     * Store a validated image as the light or dark logo and delete the file it replaces.
     */
    public function handle(User $actor, string $variant, UploadedFile $file): string
    {
        Gate::forUser($actor)->authorize('settings.edit');

        if (! in_array($variant, SiteLogo::VARIANTS, true)) {
            throw new InvalidArgumentException("Unknown logo variant [{$variant}].");
        }

        $previous = config("admin.logo.{$variant}");
        $path = $file->store('branding', ['disk' => config('admin.logo.disk')]);

        if ($path === false) {
            throw new RuntimeException('The logo could not be stored.');
        }

        $this->settings->put(["logo_{$variant}" => $path]);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            SiteLogo::disk()->delete($previous);
        }

        return $path;
    }
}
