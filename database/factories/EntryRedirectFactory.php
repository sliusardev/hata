<?php

namespace Database\Factories;

use App\Models\Entry;
use App\Models\EntryRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryRedirect>
 */
class EntryRedirectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entry_id' => Entry::factory(),
            'type' => fn (array $attributes): string => Entry::query()->findOrFail((int) $attributes['entry_id'])->type,
            'locale' => fn (array $attributes): string => Entry::query()->findOrFail((int) $attributes['entry_id'])->locale,
            'path' => fake()->unique()->slug(2),
        ];
    }
}
