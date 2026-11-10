<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Factories write no files; tests that need real files fake the disk themselves.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = (string) Str::ulid();
        $slug = fake()->unique()->slug(3);

        return [
            'key' => $key,
            'disk' => config('media.disk', 'local'),
            'path' => 'media/'.now()->format('Y/m').'/'.$key.'.webp',
            'name' => $slug.'.webp',
            'kind' => MediaKind::Image,
            'mime' => 'image/webp',
            'size' => fake()->numberBetween(10_000, 500_000),
            'width' => 800,
            'height' => 600,
            'alt' => [],
        ];
    }

    public function image(int $width = 800, int $height = 600): static
    {
        return $this->state(function () use ($width, $height): array {
            $key = (string) Str::ulid();

            return [
                'key' => $key,
                'path' => 'media/'.now()->format('Y/m').'/'.$key.'.webp',
                'name' => fake()->unique()->slug(3).'.webp',
                'kind' => MediaKind::Image,
                'mime' => 'image/webp',
                'width' => $width,
                'height' => $height,
            ];
        });
    }

    public function document(): static
    {
        return $this->state(function (): array {
            $key = (string) Str::ulid();

            return [
                'key' => $key,
                'path' => 'media/'.now()->format('Y/m').'/'.$key.'.pdf',
                'name' => fake()->unique()->slug(3).'.pdf',
                'kind' => MediaKind::Document,
                'mime' => 'application/pdf',
                'width' => null,
                'height' => null,
            ];
        });
    }

    public function inFolder(MediaFolder $folder): static
    {
        return $this->state(['folder_id' => $folder->id]);
    }
}
