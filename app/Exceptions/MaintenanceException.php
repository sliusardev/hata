<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A maintenance action that was refused before anything ran; the message is translated and shown to the user.
 */
class MaintenanceException extends RuntimeException
{
    public static function busy(): self
    {
        return new self(__('maintenance.errors.busy'));
    }

    public static function unknownSeeder(): self
    {
        return new self(__('maintenance.errors.unknown_seeder'));
    }

    public static function migrateFirst(): self
    {
        return new self(__('maintenance.errors.migrate_first'));
    }

    public static function mediaBatchRunning(): self
    {
        return new self(__('maintenance.errors.media_batch_running'));
    }
}
