<?php

use App\Actions\Content\CreateEntry;
use App\Actions\Content\DeleteEntry;
use App\Actions\Content\PruneEntryTrash;
use App\Actions\Content\RestoreEntry;
use App\Actions\Content\TrashEntry;
use App\Actions\Media\DeleteMedia;
use App\Enums\SystemRole;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\EntryRedirect;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\User;
use App\Rules\EntrySlug;
use App\Support\Content\ContentTypes;
use App\Support\Settings\SiteSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    $this->editor = User::factory()->withRole(SystemRole::Editor->value)->create();
});

/**
 * A page at `$slug` with a child and a grandchild under it.
 *
 * @return array{Entry, Entry, Entry}
 */
function trashTree(string $slug = 'about', string $locale = 'en'): array
{
    $root = Entry::factory()->page()->inLocale($locale)->create(['slug' => $slug, 'path' => $slug]);
    $child = Entry::factory()->childOf($root)->create(['slug' => 'team', 'path' => $slug.'/team']);
    $grandchild = Entry::factory()->childOf($child)->create(['slug' => 'lead', 'path' => $slug.'/team/lead']);

    return [$root, $child, $grandchild];
}

function trashedEntry(int $id): ?Entry
{
    return Entry::withTrashed()->find($id);
}

function slugIsFree(string $slug, string $locale = 'en'): bool
{
    $rule = new EntrySlug(app(ContentTypes::class)->get('pages'), $locale, null, null);

    return Validator::make(['slug' => $slug], ['slug' => [$rule]])->passes();
}

/**
 * After each slug suggestion for a top-level `team`, another "request" creates a page at the next of `$paths`.
 *
 * @param  list<string>  $paths
 * @return Closure(): list<string> the paths taken so far
 */
function takePathsAfterSuggestion(array $paths): Closure
{
    $taken = [];
    $busy = false;

    DB::listen(function (QueryExecuted $query) use (&$taken, &$busy, $paths): void {
        if ($busy || ! in_array('team-%', $query->bindings, true) || count($taken) >= count($paths)) {
            return;
        }

        $busy = true;
        $path = $paths[count($taken)];
        Entry::factory()->page()->create(['slug' => $path, 'path' => $path]);
        $taken[] = $path;
        $busy = false;
    });

    return function () use (&$taken): array {
        return $taken;
    };
}

test('trashing a page trashes its descendants at one instant; they leave the public scope and keep their paths', function () {
    [$root, $child, $grandchild] = trashTree();
    $root->update(['status' => 'published', 'published_at' => now()->subDay()]);
    $neighbour = Entry::factory()->page()->published()->create(['slug' => 'about-us', 'path' => 'about-us']);
    $otherLanguage = Entry::factory()->page()->inLocale('uk')->create(['slug' => 'about', 'path' => 'about']);

    expect($root->descendantsCount())->toBe(2);

    $count = app(TrashEntry::class)->handle($this->editor, $root);

    expect($count)->toBe(3)
        ->and($root->trashed())->toBeTrue()
        ->and(Entry::onlyTrashed()->pluck('id')->sort()->values()->all())->toBe([$root->id, $child->id, $grandchild->id])
        ->and(Entry::onlyTrashed()->distinct()->pluck('deleted_at')->all())->toHaveCount(1)
        ->and(Entry::query()->public()->pluck('id')->all())->toBe([$neighbour->id])
        ->and(trashedEntry($grandchild->id)->path)->toBe('about/team/lead')
        ->and($otherLanguage->fresh()->trashed())->toBeFalse()
        ->and(slugIsFree('about'))->toBeFalse()
        ->and($root->descendantsCount())->toBe(2);
});

test('trashing costs the same number of queries whatever the number of descendants', function () {
    $count = function (int $descendants): int {
        $root = Entry::factory()->page()->create(['slug' => 'root-'.$descendants, 'path' => 'root-'.$descendants]);
        $parents = [$root];

        for ($i = 0; $i < $descendants; $i++) {
            $parents[] = Entry::factory()->childOf($parents[intdiv($i, 2)])->create();
        }

        DB::enableQueryLog();
        $trashed = app(TrashEntry::class)->handle($this->editor, $root);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        expect($trashed)->toBe($descendants + 1);

        return $queries;
    };

    // The first permission check loads the actor's permissions; measure after it.
    $this->editor->can('pages.delete');

    $small = $count(2);

    expect($small)->toBeLessThanOrEqual(3)
        ->and($count(30))->toBe($small);
});

test('restoring brings back the descendants trashed with the page, not a child trashed earlier on its own', function () {
    [$root, $child, $grandchild] = trashTree();
    $earlier = Entry::factory()->childOf($root)->create(['slug' => 'old', 'path' => 'about/old']);

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $earlier);
    $this->travelBack();

    app(TrashEntry::class)->handle($this->editor, $root);
    $restored = app(RestoreEntry::class)->handle($this->editor, trashedEntry($root->id));

    expect($restored->trashed())->toBeFalse()
        ->and($child->fresh()->trashed())->toBeFalse()
        ->and($grandchild->fresh()->path)->toBe('about/team/lead')
        ->and(trashedEntry($earlier->id)->trashed())->toBeTrue()
        ->and(trashedEntry($earlier->id)->deleted_at->lt(now()->subHours(12)))->toBeTrue()
        ->and($restored->fresh()->path)->toBe('about');
});

test('a child trashed on its own before its parent comes back at the top level, and the parent later comes back without it', function () {
    [$root, $child, $grandchild] = trashTree();

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);

    $restored = app(RestoreEntry::class)->handle($this->editor, trashedEntry($child->id));

    expect($restored->fresh())
        ->trashed()->toBeFalse()
        ->parent_id->toBeNull()
        ->slug->toBe('team')
        ->path->toBe('team')
        ->and($grandchild->fresh()->path)->toBe('team/lead')
        ->and(EntryRedirect::query()->count())->toBe(0)
        ->and(trashedEntry($root->id)->descendantsCount())->toBe(0);

    $parent = app(RestoreEntry::class)->handle($this->editor, trashedEntry($root->id));

    expect($parent->fresh()->path)->toBe('about')
        ->and($parent->descendantsCount())->toBe(0)
        ->and($restored->fresh()->path)->toBe('team')
        ->and(Entry::onlyTrashed()->count())->toBe(0);
});

test('a page whose parent is still in the trash is restored at the top level, with a suffix when the path is taken', function () {
    [$root, $child, $grandchild] = trashTree();
    Entry::factory()->page()->create(['slug' => 'team', 'path' => 'team']);

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);

    $restored = app(RestoreEntry::class)->handle($this->editor, trashedEntry($child->id));

    expect($restored->fresh())
        ->parent_id->toBeNull()
        ->slug->toBe('team-2')
        ->path->toBe('team-2')
        ->and($grandchild->fresh()->path)->toBe('team-2/lead')
        ->and(trashedEntry($root->id)->trashed())->toBeTrue();
});

test('a page restored to the top level never takes a reserved name', function () {
    $root = Entry::factory()->page()->create(['slug' => 'section', 'path' => 'section']);
    $child = Entry::factory()->childOf($root)->create(['slug' => 'admin', 'path' => 'section/admin']);

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);

    expect(app(RestoreEntry::class)->handle($this->editor, trashedEntry($child->id))->fresh()->path)->toBe('admin-2');
});

test('a restore whose new path is taken meanwhile tries once more with a fresh suggestion', function () {
    [$root, $child, $grandchild] = trashTree();

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);

    $taken = takePathsAfterSuggestion(['team']);

    $restored = app(RestoreEntry::class)->handle($this->editor, trashedEntry($child->id));

    expect($taken())->toBe(['team'])
        ->and($restored->fresh())
        ->path->toBe('team-2')
        ->slug->toBe('team-2')
        ->parent_id->toBeNull()
        ->trashed()->toBeFalse()
        ->and($grandchild->fresh()->path)->toBe('team-2/lead');
});

test('a restore whose new path is taken twice is refused and changes nothing', function () {
    [$root, $child, $grandchild] = trashTree();

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);

    $taken = takePathsAfterSuggestion(['team', 'team-2']);

    expect(fn () => app(RestoreEntry::class)->handle($this->editor, trashedEntry($child->id)))
        ->toThrow(ProtectedActionException::class, __('content.entry.restore_conflict'))
        ->and($taken())->toBe(['team', 'team-2'])
        ->and(trashedEntry($child->id))
        ->trashed()->toBeTrue()
        ->path->toBe('about/team')
        ->parent_id->toBe($root->id)
        ->and(trashedEntry($grandchild->id)->path)->toBe('about/team/lead');
});

test('deleting for good removes every trashed record under the page, their redirects and usages, and a group left empty', function () {
    [$root, $child, $grandchild] = trashTree();
    $earlier = Entry::factory()->childOf($root)->create(['slug' => 'old', 'path' => 'about/old']);
    $translation = Entry::factory()->translationOf($child, 'uk')->create(['slug' => 'komanda', 'path' => 'komanda']);
    $media = Media::factory()->image()->create();

    EntryRedirect::create(['type' => 'pages', 'locale' => 'en', 'path' => 'old-about', 'entry_id' => $root->id]);
    EntryRedirect::create(['type' => 'pages', 'locale' => 'en', 'path' => 'about/old-lead', 'entry_id' => $grandchild->id]);
    MediaUsage::sync($root, 'image', [$media->id]);
    MediaUsage::sync($grandchild, 'body', [$media->id]);
    MediaUsage::sync($earlier, 'image', [$media->id]);
    MediaUsage::sync($root->group, 'shared.photos', [$media->id]);
    MediaUsage::sync($child->group, 'shared.photos', [$media->id]);
    MediaUsage::sync($translation, 'image', [$media->id]);

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $earlier);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);
    $trashedRoot = trashedEntry($root->id);
    $before = Entry::withTrashed()->count();
    $announced = $trashedRoot->descendantsCount();

    app(DeleteEntry::class)->handle($this->editor, $trashedRoot);

    expect($announced)->toBe(3)
        ->and($before - Entry::withTrashed()->count())->toBe($announced + 1)
        ->and(Entry::withTrashed()->pluck('id')->all())->toBe([$translation->id])
        ->and(EntryRedirect::query()->count())->toBe(0)
        ->and(EntryGroup::query()->pluck('id')->sort()->values()->all())->toBe([$child->entry_group_id])
        ->and(MediaUsage::query()->get()->map(fn (MediaUsage $usage): string => class_basename($usage->usable_type).':'.$usage->field)->sort()->values()->all())
        ->toBe(['Entry:image', 'EntryGroup:shared.photos'])
        ->and(MediaUsage::query()->where('usable_id', $translation->id)->where('field', 'image')->exists())->toBeTrue();
});

test('no live record can sit under a trashed one, so deleting for good never reaches a live record', function () {
    [$root, $child] = trashTree();
    $elsewhere = Entry::factory()->page()->create(['slug' => 'about-us', 'path' => 'about-us']);

    app(TrashEntry::class)->handle($this->editor, $root);

    expect(Entry::query()->where('path', 'like', 'about/%')->count())->toBe(0)
        ->and(fn () => app(CreateEntry::class)->handle($this->editor, app(ContentTypes::class)->get('pages'), 'en', [
            'title' => 'New', 'slug' => 'new', 'status' => 'draft', 'parent_id' => $root->id,
        ]))->toThrow(ValidationException::class);

    app(DeleteEntry::class)->handle($this->editor, trashedEntry($root->id));

    expect(Entry::withTrashed()->pluck('id')->all())->toBe([$elsewhere->id]);
});

test('after deleting a page for good its address and everything under it are free again', function () {
    [$root, $child] = trashTree();

    $this->travel(-1)->days();
    app(TrashEntry::class)->handle($this->editor, $child);
    $this->travelBack();
    app(TrashEntry::class)->handle($this->editor, $root);
    app(DeleteEntry::class)->handle($this->editor, trashedEntry($root->id));

    $pages = app(ContentTypes::class)->get('pages');
    $about = app(CreateEntry::class)->handle($this->editor, $pages, 'en', ['title' => 'About', 'slug' => 'about', 'status' => 'draft']);
    $team = app(CreateEntry::class)->handle($this->editor, $pages, 'en', ['title' => 'Team', 'slug' => 'team', 'status' => 'draft', 'parent_id' => $about->id]);

    expect($about->path)->toBe('about')
        ->and($team->path)->toBe('about/team')
        ->and($about->descendantsCount())->toBe(1)
        ->and(Entry::onlyTrashed()->count())->toBe(0);
});

test('only a record in the trash is deleted for good', function () {
    $page = Entry::factory()->page()->create();

    expect(fn () => app(DeleteEntry::class)->handle($this->editor, $page))
        ->toThrow(ProtectedActionException::class, __('content.entry.not_trashed'))
        ->and($page->fresh())->not->toBeNull();
});

test('trashing and deleting need the type\'s delete, restoring its edit', function () {
    $page = Entry::factory()->page()->create();
    $withoutDelete = User::factory()->withPermissions(['pages.view', 'pages.edit'])->create();
    $withoutEdit = User::factory()->withPermissions(['pages.view', 'pages.delete'])->create();

    expect(fn () => app(TrashEntry::class)->handle($withoutDelete, $page))->toThrow(AuthorizationException::class);

    app(TrashEntry::class)->handle($withoutEdit, $page);

    expect(fn () => app(RestoreEntry::class)->handle($withoutEdit, $page))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteEntry::class)->handle($withoutDelete, $page))->toThrow(AuthorizationException::class)
        ->and($page->fresh()->trashed())->toBeTrue();
});

test('pruning deletes what was trashed more than the configured days ago, with everything in the trash under it', function () {
    expect(config('content.trash_days'))->toBe(30);

    [$old, $oldChild, $oldGrandchild] = trashTree('old');
    [$recent, $recentChild] = trashTree('recent');
    $live = Entry::factory()->page()->create(['slug' => 'live', 'path' => 'live']);

    $this->travel(-40)->days();
    app(TrashEntry::class)->handle($this->editor, $oldChild);
    app(TrashEntry::class)->handle($this->editor, $recentChild);
    $this->travelBack();

    $this->travel(-31)->days();
    app(TrashEntry::class)->handle($this->editor, $old);
    $this->travelBack();

    $this->travel(-29)->days();
    app(TrashEntry::class)->handle($this->editor, $recent);
    $this->travelBack();

    // Removed: old (31 days) with old/team and old/team/lead (40); recent/team and its child (40) on their own.
    expect(app(PruneEntryTrash::class)->handle())->toBe(5)
        ->and(Entry::withTrashed()->pluck('id')->sort()->values()->all())->toBe([$recent->id, $live->id])
        ->and(trashedEntry($recent->id)->trashed())->toBeTrue()
        ->and(EntryGroup::query()->whereKey($old->entry_group_id)->exists())->toBeFalse()
        ->and(trashedEntry($oldGrandchild->id))->toBeNull();
});

test('pruning a record also removes a record under it trashed after the cut-off', function () {
    // Not reachable through the actions (a child is never trashed after its parent); the rule holds anyway.
    [$root, $child, $grandchild] = trashTree();
    Entry::withTrashed()->whereKey($root->id)->update(['deleted_at' => now()->subDays(31)]);
    Entry::withTrashed()->whereKey([$child->id, $grandchild->id])->update(['deleted_at' => now()->subDays(5)]);

    expect(app(PruneEntryTrash::class)->handle())->toBe(3)
        ->and(Entry::withTrashed()->count())->toBe(0);
});

test('content:prune-trash prunes and runs daily', function () {
    $page = Entry::factory()->page()->create();
    $this->travel(-31)->days();
    app(TrashEntry::class)->handle($this->editor, $page);
    $this->travelBack();

    $this->artisan('content:prune-trash')->expectsOutputToContain('Removed 1 trashed record(s).')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'content:prune-trash'));

    expect(trashedEntry($page->id))->toBeNull()
        ->and($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

test('a file deleted while its page is in the trash is gone from the page once it is restored', function () {
    $image = Media::factory()->image()->create();
    $kept = Media::factory()->image()->create();

    $page = app(CreateEntry::class)->handle($this->editor, app(ContentTypes::class)->get('pages'), 'en', [
        'title' => 'About',
        'slug' => 'about',
        'status' => 'draft',
        'image_id' => $image->id,
        'body' => '<p>Text</p><img data-media-id="'.$image->id.'" alt="Gone"><img data-media-id="'.$kept->id.'" alt="Kept">',
    ]);

    app(TrashEntry::class)->handle($this->editor, $page);
    $admin = User::factory()->withPermissions(['admin.access', 'media.view', 'media.delete'])->create();
    app(DeleteMedia::class)->handle($admin, $image);

    $restored = app(RestoreEntry::class)->handle($this->editor, trashedEntry($page->id))->fresh();

    expect($restored->image_id)->toBeNull()
        ->and($restored->body)->not->toContain('data-media-id="'.$image->id.'"')
        ->and($restored->body)->toContain('<p>Text</p>', 'data-media-id="'.$kept->id.'"');
});

test('a record restored by another request between the purge\'s selection and its delete survives with its usages', function () {
    [$root, $child] = trashTree();
    $media = Media::factory()->image()->create();
    MediaUsage::sync($child, 'image', [$media->id]);
    EntryRedirect::create(['type' => 'pages', 'locale' => 'en', 'path' => 'old-team', 'entry_id' => $child->id]);

    app(TrashEntry::class)->handle($this->editor, $root);

    $restored = false;

    DB::listen(function (QueryExecuted $query) use (&$restored, $child): void {
        $sql = strtolower($query->sql);

        if (! $restored && str_starts_with($sql, 'select') && str_contains($sql, '"entry_group_id"') && str_contains($sql, 'like')) {
            $restored = true;
            Entry::withTrashed()->whereKey($child->id)->update(['deleted_at' => null, 'parent_id' => null, 'path' => 'team']);
        }
    });

    app(DeleteEntry::class)->handle($this->editor, trashedEntry($root->id));

    expect($restored)->toBeTrue()
        ->and(trashedEntry($root->id))->toBeNull()
        ->and($child->fresh())->not->toBeNull()
        ->and(MediaUsage::query()->where('usable_id', $child->id)->where('field', 'image')->exists())->toBeTrue()
        ->and(EntryRedirect::query()->where('entry_id', $child->id)->exists())->toBeTrue()
        ->and(EntryGroup::query()->whereKey($child->entry_group_id)->exists())->toBeTrue();
});
