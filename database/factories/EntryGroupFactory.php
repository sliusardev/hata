<?php

namespace Hata\Database\Factories;

use Hata\Models\EntryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntryGroup>
 */
class EntryGroupFactory extends Factory
{
    /**
     * @var class-string<EntryGroup>
     */
    protected $model = EntryGroup::class;

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
