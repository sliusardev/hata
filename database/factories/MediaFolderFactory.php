<?php

namespace Hata\Database\Factories;

use Hata\Models\MediaFolder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaFolder>
 */
class MediaFolderFactory extends Factory
{
    /**
     * @var class-string<MediaFolder>
     */
    protected $model = MediaFolder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
