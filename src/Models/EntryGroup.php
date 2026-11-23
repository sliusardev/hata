<?php

namespace Hata\Models;

use App\Models\User;
use Hata\Contracts\UsesMedia;
use Hata\Database\Factories\EntryGroupFactory;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One translation set: the records of a piece of content in each language, and the values of the
 * fields the type declares shared. Files used by shared fields are recorded against the group (`shared.<name>`).
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed>|null $shared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'shared'])]
#[UseFactory(EntryGroupFactory::class)]
class EntryGroup extends Model implements UsesMedia
{
    /** @use HasFactory<EntryGroupFactory> */
    use HasFactory;

    /**
     * The languages a group holds a record in, the trash included (the unique index counts both): the one answer
     * to "is this language taken" for creating, linking and moving a record.
     *
     * @return list<string>
     */
    public static function takenLocales(int $groupId): array
    {
        return array_values(Entry::withTrashed()->where('entry_group_id', $groupId)->pluck('locale')->all());
    }

    /**
     * The group's record in a language, trashed or not.
     */
    public static function recordIn(int $groupId, string $locale): ?Entry
    {
        return Entry::withTrashed()->where('entry_group_id', $groupId)->where('locale', $locale)->first();
    }

    /**
     * Deletes those of the groups that have no record left (trashed ones counted), with their media usages. For the
     * caller's transaction, after it took records out of the groups: the one place a group ends.
     *
     * @param  list<int>  $groupIds
     */
    public static function deleteEmptied(array $groupIds): void
    {
        $emptied = self::query()
            ->whereKey($groupIds)
            ->whereDoesntHave('entries', fn (Builder $query) => $query->withTrashed())
            ->pluck('id')
            ->all();

        if ($emptied === []) {
            return;
        }

        MediaUsage::query()->where('usable_type', (new self)->getMorphClass())->whereIn('usable_id', $emptied)->delete();
        self::query()->whereKey($emptied)->delete();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['shared' => 'array'];
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * "Page: About us (all languages)", named after the record in the default content language, else the oldest.
     */
    public function mediaUsageLabel(string $field): string
    {
        $entry = $this->representative();

        return __('hata::content.usage.shared', [
            'type' => $entry?->typeLabel() ?? app(ContentTypes::class)->find($this->type)?->label() ?? $this->type,
            'title' => $entry->title ?? '#'.$this->id,
        ]);
    }

    public function mediaUsageUrl(string $field, ?User $viewer): ?string
    {
        return $this->representative()?->mediaUsageUrl($field, $viewer);
    }

    /**
     * Empties a shared file field, or takes the file out of a shared gallery. Runs inside the delete's transaction.
     */
    public function forgetMedia(string $field, int $mediaId): void
    {
        if (! str_starts_with($field, 'shared.')) {
            return;
        }

        $name = substr($field, strlen('shared.'));
        $definition = app(ContentTypes::class)->find($this->type)?->field($name);
        $shared = $this->shared ?? [];

        if ($definition === null || ! array_key_exists($name, $shared)) {
            return;
        }

        $shared[$name] = $definition->withoutMedia($shared[$name], $mediaId);
        $this->shared = $shared;

        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * The record that names the group: the one in the default content language, else the oldest.
     */
    private function representative(): ?Entry
    {
        return $this->entries()
            ->orderByRaw('locale = ? desc', [app(ContentLocales::class)->defaultCode()])
            ->orderBy('id')
            ->first();
    }
}
