<?php

namespace Database\Factories;

use App\Enums\MaintenanceAction;
use App\Enums\MaintenanceStatus;
use App\Models\MaintenanceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRun>
 */
class MaintenanceRunFactory extends Factory
{
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
