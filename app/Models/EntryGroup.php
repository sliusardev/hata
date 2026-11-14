<?php

namespace App\Models;

use App\Contracts\UsesMedia;
use App\Support\Content\ContentTypes;
use App\Support\Localization\ContentLocales;
use Database\Factories\EntryGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
class EntryGroup extends Model implements UsesMedia
{
    /** @use HasFactory<EntryGroupFactory> */
    use HasFactory;

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

        return __('content.usage.shared', [
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
