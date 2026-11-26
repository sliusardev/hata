<?php

namespace Hata\Support\Content;

use Hata\Models\Entry;
use Hata\Support\Content\Types\PagesType;
use Illuminate\Support\Facades\DB;

/**
 * The page chosen as the site's home (`hata.content.home_page`): the record itself and its translations, whatever
 * their state. Bound `scoped`, so what it remembers lives for one request.
 */
class HomePage
{
    /**
     * @var array<string, ?Entry>
     */
    private array $records = [];

    private bool $groupKnown = false;

    private ?int $group = null;

    /**
     * The chosen record's id, or null when none is chosen.
     */
    public function id(): ?int
    {
        $id = config('hata.content.home_page');

        return is_int($id) ? $id : null;
    }

    /**
     * The record of the pages type in the chosen record's group and the language, in any state (the caller checks
     * `isPublic()`). One query, by a subquery on the group, and one for its image when it has one and its type shows
     * images.
     */
    public function recordFor(string $locale): ?Entry
    {
        if (array_key_exists($locale, $this->records)) {
            return $this->records[$locale];
        }

        $id = $this->id();

        if ($id === null) {
            return $this->records[$locale] = null;
        }

        $record = Entry::query()
            ->ofType(PagesType::KEY)
            ->where('locale', $locale)
            ->where('entry_group_id', fn ($query) => $query->select('entry_group_id')->from('entries')->where('id', $id))
            ->first();

        $record?->loadPublicImage();

        return $this->records[$locale] = $record;
    }

    /**
     * Whether the record is a page of the chosen record's group (the chosen one itself or a translation). The loaded
     * `groupEntries` only proves a match: ContentRouter filters it (public records, active languages), so a miss there
     * falls back to the group's id, by at most one query per request.
     */
    public function includes(Entry $entry): bool
    {
        $id = $this->id();

        if ($id === null || $entry->type !== PagesType::KEY) {
            return false;
        }

        if ($entry->id === $id) {
            return true;
        }

        if ($entry->relationLoaded('groupEntries') && $entry->groupEntries->contains('id', $id)) {
            return true;
        }

        $group = $this->groupId();

        return $group !== null && $entry->entry_group_id === $group;
    }

    /**
     * The chosen record's current group (trashed included), by one query, remembered for the request.
     */
    public function groupId(): ?int
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if (! $this->groupKnown) {
            $group = DB::table('entries')->where('id', $id)->value('entry_group_id');
            $this->group = $group === null ? null : (int) $group;
            $this->groupKnown = true;
        }

        return $this->group;
    }
}
