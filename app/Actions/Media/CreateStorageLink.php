<?php

namespace App\Actions\Media;

use App\Exceptions\StorageLinkException;
use App\Models\User;
use App\Support\Media\DirectServing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CreateStorageLink
{
    public function __construct(private readonly DirectServing $directServing) {}

    /**
     * Run `storage:link` and check that the link now resolves to the `public` disk.
     *
     * @throws StorageLinkException when the link could not be created
     */
    public function handle(User $actor): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        try {
            Artisan::call('storage:link');
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->directServing->refresh();

        if (! $this->directServing->linked()) {
            throw new StorageLinkException(__('site_settings.media.storage.direct.link_failed', ['command' => 'php artisan storage:link']));
        }
    }
}
