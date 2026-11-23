<?php

namespace Hata\Actions\Maintenance;

use App\Models\User;
use Hata\Exceptions\MaintenanceException;
use Hata\Support\Maintenance\LogFiles;
use Illuminate\Support\Facades\Gate;

/**
 * Deletes one file of the logs folder. A channel that was writing to it creates it again on its next write.
 */
class DeleteLogFile
{
    public function __construct(private readonly LogFiles $logFiles) {}

    /**
     * @throws MaintenanceException when the file is not one the logs folder lists, or can't be deleted
     */
    public function handle(User $actor, string $name): void
    {
        Gate::forUser($actor)->authorize('maintenance.run');

        $path = $this->logFiles->resolve($name);

        if ($path === null) {
            throw MaintenanceException::logFileMissing();
        }

        if (! @unlink($path)) {
            throw MaintenanceException::logFileNotDeleted();
        }
    }
}
