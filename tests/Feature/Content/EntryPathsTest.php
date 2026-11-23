<?php

use Hata\Models\Entry;
use Hata\Models\EntryRedirect;
use Hata\Rules\EntrySlug;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\EntryPaths;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(ContentTypes::class)->register(new ProductsType);
    $this->pages = app(ContentTypes::class)->get('pages');
    $this->paths = app(EntryPaths::class);
});

/**
 * @return list<string> the validation errors of a slug typed for a page
 */
function slugErrors(string $slug, ?Entry $parent = null, ?Entry $ignore = null, string $locale = 'en'): array
{
    $rule = new EntrySlug(app(ContentTypes::class)->get('pages'), $locale, $parent, $ignore);

    return Validator::make(['slug' => $slug], ['slug' => [$rule]])->errors()->get('slug');
}

/**
 * A root page with `$count` descendants spread over three levels.
 *
 * @return array{0: Entry, 1: list<Entry>}
 */
function pageTree(int $count): array
{
    $root = Entry::factory()->page()->create(['slug' => 'root', 'path' => 'root']);
    $last = [-1 => $root];
    $all = [];

    for ($i = 0; $i < $count; $i++) {
        $level = $i % 3;
        $all[] = $last[$level] = Entry::factory()->childOf($last[$level - 1])->create();
    }

    return [$root, $all];
}

test('a title becomes a slug', function () {
    expect($this->paths->slugFrom('Pro nas'))->toBe('pro-nas')
        ->and($this->paths->slugFrom('!!!'))->toBe('');
});

test('suggest adds a number while the path is taken, trashed records included', function () {
    expect($this->paths->suggest($this->pages, 'en', null, 'Pro nas'))->toBe('pro-nas');

    $first = Entry::factory()->page()->create(['slug' => 'pro-nas', 'path' => 'pro-nas']);

    expect($this->paths->suggest($this->pages, 'en', null, 'Pro nas'))->toBe('pro-nas-2')
        ->and($this->paths->suggest($this->pages, 'uk', null, 'Pro nas'))->toBe('pro-nas')
        ->and($this->paths->suggest($this->pages, 'en', null, 'Pro nas', $first))->toBe('pro-nas');

    $first->delete();

    expect($this->paths->suggest($this->pages, 'en', null, 'Pro nas'))->toBe('pro-nas-2');

    Entry::factory()->page()->create(['slug' => 'pro-nas-2', 'path' => 'pro-nas-2']);

    expect($this->paths->suggest($this->pages, 'en', null, 'Pro nas'))->toBe('pro-nas-3');
});

test('suggest never gives a reserved name and looks under the parent', function () {
    expect($this->paths->suggest($this->pages, 'en', null, 'Admin'))->toBe('admin-2');

    $parent = Entry::factory()->page()->create(['slug' => 'info', 'path' => 'info']);

    expect($this->paths->suggest($this->pages, 'en', $parent, 'Admin'))->toBe('admin');

    Entry::factory()->childOf($parent)->create(['slug' => 'admin']);

    expect($this->paths->suggest($this->pages, 'en', $parent, 'Admin'))->toBe('admin-2');
});

test('suggest agrees with the slug rule on what is reserved', function (string $title, string $expected) {
    $slug = $this->paths->suggest($this->pages, 'en', null, $title);

    expect($slug)->toBe($expected)
        ->and(slugErrors($slug))->toBe([]);
})->with([
    'a type base' => ['Products', 'products-2'],
    'a route' => ['Admin', 'admin-2'],
    'a language code' => ['En', 'en-2'],
    'a free name' => ['About', 'about'],
]);

test('the path is the parent path and the slug', function () {
    $parent = Entry::factory()->page()->create(['slug' => 'info', 'path' => 'info']);

    expect($this->paths->pathFor($this->pages, null, 'about'))->toBe('about')
        ->and($this->paths->pathFor($this->pages, $parent, 'delivery'))->toBe('info/delivery');
});

test('the slug rule refuses a bad format', function (string $slug) {
    expect(slugErrors($slug))->not->toBe([]);
})->with(['About', 'a--b', '-a', 'a-', 'a b', 'a/b', 'a trailing newline' => "about\n"]);

test('the slug rule refuses reserved names at the top level', function (string $slug) {
    expect(slugErrors($slug))->not->toBe([]);
})->with(['admin', 'login', 'storage', 'en', 'products']);

test('the slug rule accepts a reserved name below a parent', function () {
    $parent = Entry::factory()->page()->create(['slug' => 'info', 'path' => 'info']);

    expect(slugErrors('admin', $parent))->toBe([])
        ->and(slugErrors('about'))->toBe([]);
});

test('the slug rule refuses a taken sibling, trashed or not, and lets the record keep its own', function () {
    $page = Entry::factory()->page()->create(['slug' => 'about', 'path' => 'about']);

    expect(slugErrors('about'))->toBe([__('hata::content.slug.taken')])
        ->and(slugErrors('about', null, $page))->toBe([])
        ->and(slugErrors('about', null, null, 'uk'))->toBe([]);

    $page->delete();

    expect(slugErrors('about'))->toBe([__('hata::content.slug.taken_trashed')]);
});

test('the slug rule refuses a path over 255 characters', function () {
    $parent = Entry::factory()->page()->create(['slug' => 'info', 'path' => str_repeat('a', 250)]);

    expect(slugErrors(str_repeat('b', 256)))->not->toBe([])
        ->and(slugErrors('bbbbb', $parent))->not->toBe([])
        ->and(slugErrors('bbbb', $parent))->toBe([]);
});

test('the slug rule messages are translated', function () {
    expect(slugErrors('About')[0])->toBe(__('hata::content.slug.format'))
        ->and(slugErrors('admin')[0])->toBe(__('hata::content.slug.reserved'));
});

test('moving a page rewrites every descendant and keeps the old paths as redirects', function () {
    [$root, $descendants] = pageTree(50);
    $old = Entry::query()->whereKey(collect($descendants)->pluck('id'))->pluck('path', 'id');
    $descendants[0]->delete();
    $other = Entry::factory()->page()->inLocale('uk')->create(['slug' => 'root', 'path' => 'root']);

    DB::transaction(fn () => $this->paths->move($root, 'moved'));

    expect($root->fresh()->path)->toBe('moved')
        ->and(Entry::withTrashed()->where('path', 'like', 'root%')->where('locale', 'en')->count())->toBe(0)
        ->and($other->fresh()->path)->toBe('root')
        ->and(EntryRedirect::count())->toBe(51);

    foreach ($descendants as $descendant) {
        $path = Entry::withTrashed()->find($descendant->id)->path;

        expect($path)->toBe('moved'.substr($old[$descendant->id], 4));
        expect(EntryRedirect::where('path', $old[$descendant->id])->value('entry_id'))->toBe($descendant->id);
    }

    expect(EntryRedirect::where('path', 'root')->first())
        ->entry_id->toBe($root->id)
        ->type->toBe('pages')
        ->locale->toBe('en');
});

test('moving costs the same number of queries whatever the number of descendants', function () {
    $count = function (int $descendants): int {
        [$root] = pageTree($descendants);

        DB::enableQueryLog();
        DB::transaction(fn () => $this->paths->move($root, 'moved-'.$descendants));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    };

    $small = $count(2);

    expect($count(50))->toBe($small);
});

test('moving onto a path held by a redirect removes that redirect', function () {
    $first = Entry::factory()->page()->create(['slug' => 'old', 'path' => 'old']);
    $second = Entry::factory()->page()->create(['slug' => 'x', 'path' => 'x']);

    DB::transaction(fn () => $this->paths->move($first, 'new'));

    expect(EntryRedirect::where('path', 'old')->exists())->toBeTrue();

    DB::transaction(fn () => $this->paths->move($second, 'old'));

    expect(EntryRedirect::where('path', 'old')->exists())->toBeFalse()
        ->and(EntryRedirect::where('path', 'x')->value('entry_id'))->toBe($second->id)
        ->and($second->fresh()->path)->toBe('old');
});

test('moving a page back drops its own redirect and moving on again records the path once', function () {
    $page = Entry::factory()->page()->create(['slug' => 'a', 'path' => 'a']);

    DB::transaction(fn () => $this->paths->move($page, 'b'));
    DB::transaction(fn () => $this->paths->move($page, 'a'));

    expect(EntryRedirect::pluck('path')->all())->toBe(['b']);

    DB::transaction(fn () => $this->paths->move($page, 'b'));

    expect(EntryRedirect::pluck('path')->all())->toBe(['a']);
});

test('moving to the same path does nothing', function () {
    $page = Entry::factory()->page()->create(['slug' => 'a', 'path' => 'a']);

    $this->paths->move($page, 'a');

    expect(EntryRedirect::count())->toBe(0);
});

test('a redirect row that already exists for an old path is pointed at the record that left it', function () {
    $gone = Entry::factory()->page()->create(['slug' => 'a', 'path' => 'a']);
    DB::transaction(fn () => $this->paths->move($gone, 'b'));

    $holder = Entry::factory()->page()->create(['slug' => 'a', 'path' => 'a']);
    DB::transaction(fn () => $this->paths->move($holder, 'c'));

    expect(EntryRedirect::where('path', 'a')->count())->toBe(1)
        ->and(EntryRedirect::where('path', 'a')->value('entry_id'))->toBe($holder->id);
});

test('every statement of a move names the table with the connection\'s prefix', function () {
    $parent = Entry::factory()->create(['slug' => 'old', 'path' => 'old']);
    Entry::factory()->childOf($parent)->create(['slug' => 'child', 'path' => 'old/child']);

    DB::connection()->setTablePrefix('kit_');

    try {
        // Nothing runs (the tables are not really prefixed here); the statements are what is checked.
        $statements = collect(DB::pretend(fn () => app(EntryPaths::class)->move($parent, 'new')))->pluck('query');
    } finally {
        DB::connection()->setTablePrefix('');
    }

    $onEntries = $statements->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'entries'));

    expect($onEntries->filter(fn (string $sql): bool => str_starts_with(strtolower($sql), 'update'))->count())->toBeGreaterThanOrEqual(2)
        ->and($onEntries->every(fn (string $sql): bool => str_contains($sql, 'kit_entries')))->toBeTrue();
});
