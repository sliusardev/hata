<?php

namespace App\Models;

use Database\Factories\EntryGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One translation set: the records of a piece of content in each language, and the values of the
 * fields the type declares shared.
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed>|null $shared
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'shared'])]
class EntryGroup extends Model
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
}
