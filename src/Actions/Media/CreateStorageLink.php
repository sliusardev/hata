<?php

namespace Hata\Actions\Media;

use Hata\Contracts\AdminUser;
use Hata\Exceptions\StorageLinkException;
use Hata\Support\Media\DirectServing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Throwable;

class CreateStorageLink
{
    public function __construct(private readonly DirectServing $directServing) {}

    /**
     * Run `storage:link --force` and check that the link now resolves to the `public` disk. `--force` replaces an
     * existing symlink (a stale one pointing elsewhere); Laravel never replaces a real file or directory in the
     * link's place, so that case ends in the exception, whose message names the command to run over SSH.
     *
     * @throws StorageLinkException when the link could not be created
     */
    public function handle(AdminUser $actor): void
    {
        Gate::forUser($actor)->authorize('settings.edit');

        try {
            Artisan::call('storage:link', ['--force' => true]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->directServing->refresh();

        if (! $this->directServing->linked()) {
            throw new StorageLinkException(__('hata::site_settings.media.storage.direct.link_failed', ['command' => 'php artisan storage:link --force']));
        }
    }
}
