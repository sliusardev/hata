<?php

namespace App\Support\Media;

use App\Enums\MediaKind;
use App\Models\Media;
use App\Models\MediaFolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The media library's filters, shared by the library page and the media picker so both
 * narrow the files the same way.
 */
final readonly class MediaFilters
{
    /**
     * @param  string  $type  A `MediaKind` value; anything else means all kinds.
     * @param  string  $month  `YYYY-MM` in the site timezone, or empty.
     * @param  list<string>|null  $extensions  Lowercase extensions of the current name to keep; null means any.
     */
    public function __construct(
        public string $search = '',
        public string $type = 'all',
        public string $month = '',
        public ?MediaFolder $folder = null,
        public bool $noFolder = false,
        public bool $unused = false,
        public ?array $extensions = null,
    ) {}

    /**
     * @param  Builder<Media>  $query
     * @return Builder<Media>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->noFolder, fn (Builder $query) => $query->whereNull('folder_id'))
            ->when($this->folder, fn (Builder $query, MediaFolder $folder) => $query->where('folder_id', $folder->id))
            ->when(MediaKind::tryFrom($this->type), fn (Builder $query, MediaKind $kind) => $query->where('kind', $kind))
            ->when(self::monthRange($this->month), fn (Builder $query, array $range) => $query->whereBetween('created_at', $range))
            ->when($this->unused, fn (Builder $query) => $query->doesntHave('usages'))
            ->when($this->extensions !== null, fn (Builder $query) => $this->limitToExtensions($query))
            ->search($this->search);
    }

    /**
     * A month as a UTC range, so no database-specific date SQL is needed.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public static function monthRange(string $month): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('!Y-m', $month, (string) config('admin.timezone'));

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return [$start->utc(), $start->endOfMonth()->utc()];
    }

    /**
     * @param  Builder<Media>  $query
     */
    private function limitToExtensions(Builder $query): void
    {
        $extensions = array_values(array_unique(array_map('strtolower', $this->extensions ?? [])));

        $query->where(function (Builder $query) use ($extensions): void {
            foreach ($extensions as $extension) {
                $escaped = addcslashes($extension, '\\%_');
                $query->orWhereRaw("lower(name) like ? escape '\\'", ['%.'.$escaped]);
            }

            if ($extensions === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
