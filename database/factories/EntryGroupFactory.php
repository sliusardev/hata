<?php

namespace Database\Factories;

use App\Models\EntryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryGroup>
 */
class EntryGroupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'pages',
            'shared' => null,
        ];
    }
}
