<?php

namespace App\Models;

use Database\Factories\EntryRedirectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An old path of a record that now lives at another one.
 *
 * @property int $id
 * @property string $type
 * @property string $locale
 * @property string $path
 * @property int $entry_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'locale', 'path', 'entry_id'])]
class EntryRedirect extends Model
{
    /** @use HasFactory<EntryRedirectFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Entry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Entry::class);
    }
}
