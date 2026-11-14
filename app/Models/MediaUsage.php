<?php

namespace App\Models;

use App\Contracts\UsesMedia;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
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
     * The model that uses the file, trashed ones included (a record in the trash still holds its files and must be
     * told when one is deleted). Models without soft deletes are loaded as before.
     *
     * @return MorphTo<Model, $this>
     */
    public function usable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
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

    /**
     * What the usable calls this place; "Type #id" when it does not name itself or no longer exists.
     */
    public function label(): string
    {
        $usable = $this->usesMedia();

        return $usable?->mediaUsageLabel($this->field) ?? __('media.details.usage_item', [
            'type' => class_basename($this->usable_type),
            'id' => $this->usable_id,
        ]);
    }

    /**
     * Where the viewer can open the place, or null.
     */
    public function url(?User $viewer): ?string
    {
        return $this->usesMedia()?->mediaUsageUrl($this->field, $viewer);
    }

    /**
     * Tell every usable that implements UsesMedia to drop its reference to these files.
     * Call before the media rows are deleted: the usage rows cascade away with them.
     *
     * @param  iterable<Media>  $media
     */
    public static function forgetAll(iterable $media): void
    {
        $ids = [];

        foreach ($media as $item) {
            $ids[] = $item->id;
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (static::query()->whereIn('media_id', $chunk)->get() as $usage) {
                $usable = $usage->usesMedia();
                $usable?->forgetMedia($usage->field, $usage->media_id);
            }
        }
    }

    /**
     * The usable model when it exists and implements UsesMedia. An unknown morph type is not an error here.
     */
    private function usesMedia(): (Model&UsesMedia)|null
    {
        $class = Relation::getMorphedModel($this->usable_type) ?? $this->usable_type;

        if (! is_a($class, UsesMedia::class, true) || ! is_a($class, Model::class, true)) {
            return null;
        }

        $usable = $this->usable;

        return $usable instanceof UsesMedia ? $usable : null;
    }
}
