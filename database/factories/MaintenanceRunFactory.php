<?php

namespace Hata\Database\Factories;

use Hata\Enums\MaintenanceAction;
use Hata\Enums\MaintenanceStatus;
use Hata\Models\MaintenanceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRun>
 */
class MaintenanceRunFactory extends Factory
{
    /**
     * @var class-string<MaintenanceRun>
     */
    protected $model = MaintenanceRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'user_name' => fake()->name(),
            'action' => MaintenanceAction::ClearCaches,
            'parameter' => null,
            'status' => MaintenanceStatus::Succeeded,
            'exit_code' => 0,
            'output' => 'Done.',
            'output_truncated' => false,
            'started_at' => now()->subSeconds(2),
            'finished_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state([
            'status' => MaintenanceStatus::Running,
            'slot' => MaintenanceRun::SLOT,
            'exit_code' => null,
            'output' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }
}
