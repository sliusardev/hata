<?php

use App\Models\User;
use Hata\Enums\EntryState;
use Hata\Enums\EntryStatus;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(ContentTypes::class)->register(new ProductsType);
});

test('public() returns a published record with a past date and hides a draft, a future date and a trashed one', function () {
    $live = Entry::factory()->page()->published()->create(['slug' => 'live']);
    Entry::factory()->page()->draft()->create(['slug' => 'draft']);
    Entry::factory()->page()->scheduled()->create(['slug' => 'later']);
    Entry::factory()->page()->published()->create(['slug' => 'gone'])->delete();

    expect(Entry::query()->public()->pluck('id')->all())->toBe([$live->id]);
});

test('state() tells draft, scheduled and published apart', function () {
    expect(Entry::factory()->page()->draft()->create()->state())->toBe(EntryState::Draft)
        ->and(Entry::factory()->page()->scheduled()->create()->state())->toBe(EntryState::Scheduled)
        ->and(Entry::factory()->page()->published()->create()->state())->toBe(EntryState::Published)
        ->and(Entry::factory()->page()->scheduled()->create()->status)->toBe(EntryStatus::Published)
        ->and(Entry::factory()->page()->published()->create()->isPublic())->toBeTrue()
        ->and(Entry::factory()->page()->scheduled()->create()->isPublic())->toBeFalse();
});

test('a group holds one record per language', function () {
    $entry = Entry::factory()->page()->inLocale('en')->create();

    expect(fn () => Entry::factory()->page()->create([
        'entry_group_id' => $entry->entry_group_id,
        'locale' => 'en',
        'slug' => 'other',
        'path' => 'other',
    ]))->toThrow(QueryException::class);
});

test('a path is unique inside a type and a language', function () {
    Entry::factory()->page()->inLocale('uk')->create(['slug' => 'about', 'path' => 'about']);

    expect(fn () => Entry::factory()->page()->inLocale('uk')->create(['slug' => 'about', 'path' => 'about']))
        ->toThrow(QueryException::class);

    Entry::factory()->page()->inLocale('en')->create(['slug' => 'about', 'path' => 'about']);
    Entry::factory()->create(['type' => 'products', 'locale' => 'uk', 'slug' => 'about', 'path' => 'about']);

    expect(Entry::query()->where('path', 'about')->count())->toBe(3);
});

test('translations() lists the other languages of the group and skips the trashed', function () {
    $en = Entry::factory()->page()->inLocale('en')->create();
    $uk = Entry::factory()->page()->translationOf($en, 'uk')->create();
    Entry::factory()->page()->translationOf($en, 'de')->create()->delete();

    expect($en->translations()->pluck('id')->all())->toBe([$uk->id])
        ->and($uk->translations()->pluck('id')->all())->toBe([$en->id])
        ->and($uk->entry_group_id)->toBe($en->entry_group_id)
        ->and($en->group->entries()->count())->toBe(2);
});

test('translations() on eager loaded records give the others of each group with no query per record', function () {
    $a = Entry::factory()->page()->inLocale('en')->create();
    $aUk = Entry::factory()->page()->translationOf($a, 'uk')->create();
    $b = Entry::factory()->page()->inLocale('en')->create();
    $bDe = Entry::factory()->page()->translationOf($b, 'de')->create();
    Entry::factory()->page()->translationOf($b, 'fr')->create()->delete();

    $entries = Entry::query()->whereKey([$a->id, $aUk->id, $b->id, $bDe->id])->with('groupEntries')->orderBy('id')->get();

    DB::enableQueryLog();
    $others = $entries->map(fn (Entry $entry): array => $entry->translations()->pluck('id')->all())->all();

    expect(DB::getQueryLog())->toBe([])
        ->and($others)->toBe([[$aUk->id], [$a->id], [$bDe->id], [$b->id]])
        ->and($entries->first()->groupEntries->pluck('id')->all())->toBe([$a->id, $aUk->id]);
});

test('a published record without a date is a draft and not public', function () {
    $entry = Entry::factory()->page()->create(['status' => EntryStatus::Published, 'published_at' => null]);

    expect($entry->state())->toBe(EntryState::Draft)
        ->and($entry->isPublic())->toBeFalse()
        ->and(Entry::query()->public()->count())->toBe(0);
});

test('children, parent, path and depth follow the tree', function () {
    $parent = Entry::factory()->page()->create(['slug' => 'company', 'path' => 'company']);
    $child = Entry::factory()->page()->childOf($parent)->create(['slug' => 'team']);
    $grandchild = Entry::factory()->page()->childOf($child)->create(['slug' => 'people']);

    expect($child->path)->toBe('company/team')
        ->and($grandchild->path)->toBe('company/team/people')
        ->and($parent->depth())->toBe(0)
        ->and($grandchild->depth())->toBe(2)
        ->and($grandchild->parent->is($child))->toBeTrue()
        ->and($parent->children->pluck('id')->all())->toBe([$child->id])
        ->and($child->locale)->toBe($parent->locale);
});

test('deleting a user nulls author_id and deleting the parent nulls parent_id', function () {
    $author = User::factory()->create();
    $parent = Entry::factory()->page()->create();
    $child = Entry::factory()->page()->childOf($parent)->create(['author_id' => $author->id]);

    $author->delete();
    $parent->forceDelete();

    expect($child->fresh()->author_id)->toBeNull()
        ->and($child->fresh()->parent_id)->toBeNull();
});

test('url() is the path in the default language and prefixed in another active one', function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'uk',
    ]);

    $uk = Entry::factory()->page()->inLocale('uk')->create(['slug' => 'about', 'path' => 'about']);
    $en = Entry::factory()->page()->translationOf($uk, 'en')->create(['slug' => 'about', 'path' => 'about']);

    expect($uk->url(false))->toBe('/about')
        ->and($en->url(false))->toBe('/en/about')
        ->and($uk->url())->toBe(url('/about'))
        ->and($en->url())->toBe(url('/en/about'));
});

test('url() puts the base of the type before the path', function () {
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true], ['code' => 'uk', 'active' => true]],
        'content_default_locale' => 'en',
    ]);

    $product = Entry::factory()->create(['type' => 'products', 'locale' => 'uk', 'slug' => 'mug', 'path' => 'mug']);

    expect($product->url(false))->toBe('/uk/products/mug');
});

test('fieldValue() reads a local field from the record and a shared one from the group', function () {
    $group = EntryGroup::factory()->create(['type' => 'products', 'shared' => ['price' => 25]]);
    $entry = Entry::factory()->create([
        'entry_group_id' => $group->id,
        'type' => 'products',
        'fields' => ['subtitle' => 'Blue'],
    ]);

    expect($entry->fieldValue('subtitle'))->toBe('Blue')
        ->and($entry->fieldValue('price'))->toBe(25)
        ->and($entry->fieldValue('summary'))->toBeNull()
        ->and($entry->type()->key())->toBe('products');
});

test('the search index is built from the title and the slug', function () {
    Entry::factory()->page()->create(['title' => 'Про Нас', 'slug' => 'about-us']);

    expect(Entry::query()->search('про нас')->count())->toBe(1)
        ->and(Entry::query()->search('ABOUT-us')->count())->toBe(1)
        ->and(Entry::query()->search('missing')->count())->toBe(0);
});

test('ofType and inLocale narrow the query', function () {
    Entry::factory()->page()->inLocale('en')->create();
    Entry::factory()->page()->inLocale('uk')->create();
    Entry::factory()->create(['type' => 'products', 'locale' => 'en']);

    expect(Entry::query()->ofType('pages')->count())->toBe(2)
        ->and(Entry::query()->ofType('pages')->inLocale('uk')->count())->toBe(1);
});
