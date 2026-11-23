<?php

namespace Hata\Database\Factories;

use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the record's group too and sets the path from the parent. A draft in `pages` and `en` by default.
 *
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
{
    /**
     * @var class-string<Entry>
     */
    protected $model = Entry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(3);

        return [
            'type' => 'pages',
            'locale' => 'en',
            'parent_id' => null,
            'entry_group_id' => fn (array $attributes): int => EntryGroup::factory()->create(['type' => $attributes['type']])->id,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'path' => function (array $attributes): string {
                if ($attributes['parent_id'] === null) {
                    return $attributes['slug'];
                }

                return Entry::query()->withTrashed()->findOrFail((int) $attributes['parent_id'])->path.'/'.$attributes['slug'];
            },
            'status' => EntryStatus::Draft,
            'published_at' => null,
        ];
    }

    public function page(): static
    {
        return $this->state(['type' => 'pages']);
    }

    public function draft(): static
    {
        return $this->state(['status' => EntryStatus::Draft, 'published_at' => null]);
    }

    public function published(): static
    {
        return $this->state(['status' => EntryStatus::Published, 'published_at' => now()->subDay()]);
    }

    public function scheduled(): static
    {
        return $this->state(['status' => EntryStatus::Published, 'published_at' => now()->addDay()]);
    }

    public function inLocale(string $locale): static
    {
        return $this->state(['locale' => $locale]);
    }

    public function childOf(Entry $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'type' => $parent->type,
            'locale' => $parent->locale,
        ]);
    }

    public function translationOf(Entry $source, string $locale): static
    {
        return $this->state([
            'entry_group_id' => $source->entry_group_id,
            'type' => $source->type,
            'locale' => $locale,
        ]);
    }
}
