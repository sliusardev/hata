<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $media_id
 * @property string $usable_type
 * @property int $usable_id
 * @property string $field
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['media_id', 'usable_type', 'usable_id', 'field'])]
class MediaUsage extends Model
{
    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Make the given media ids the only ones used by the model's field.
     *
     * @param  array<int, int>  $mediaIds
     */
    public static function sync(Model $usable, string $field, array $mediaIds): void
    {
        $mediaIds = array_values(array_unique($mediaIds));

        $scope = static::query()
            ->where('usable_type', $usable->getMorphClass())
            ->where('usable_id', $usable->getKey())
            ->where('field', $field);

        (clone $scope)->whereNotIn('media_id', $mediaIds)->delete();

        $existing = (clone $scope)->pluck('media_id')->all();

        foreach (array_diff($mediaIds, $existing) as $mediaId) {
            static::query()->create([
                'media_id' => $mediaId,
                'usable_type' => $usable->getMorphClass(),
                'usable_id' => $usable->getKey(),
                'field' => $field,
            ]);
        }
    }
}
