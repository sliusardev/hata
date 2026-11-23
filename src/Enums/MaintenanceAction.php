<?php

namespace App\Enums;

/**
 * The fixed list of actions the maintenance page can run. Nothing outside it is ever passed to Artisan.
 */
enum MaintenanceAction: string
{
    case Migrate = 'migrate';
    case Seed = 'seed';
    case ClearCaches = 'clear_caches';
    case RebuildCaches = 'rebuild_caches';

    /**
     * The Artisan command and its options.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function command(?string $seeder = null): array
    {
        return match ($this) {
            self::Migrate => ['migrate', ['--force' => true]],
            self::Seed => ['db:seed', ['--class' => $seeder, '--force' => true]],
            self::ClearCaches => ['optimize:clear', []],
            self::RebuildCaches => ['optimize', []],
        };
    }

    public function label(): string
    {
        return __('maintenance.actions.'.$this->value.'.title');
    }
}
