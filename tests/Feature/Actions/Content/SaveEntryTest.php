<?php

use App\Actions\Content\CreateEntry;
use App\Actions\Content\UpdateEntry;
use App\Enums\EntryState;
use App\Enums\EntryStatus;
use App\Enums\SystemRole;
use App\Exceptions\ProtectedActionException;
use App\Models\Entry;
use App\Models\EntryGroup;
use App\Models\EntryRedirect;
use App\Models\Media;
use App\Models\MediaUsage;
use App\Models\User;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use App\Support\Content\EntryAuthors;
use App\Support\Content\EntryInput;
use App\Support\Content\Fields\Gallery;
use App\Support\Content\Fields\MediaFile;
use App\Support\Content\Fields\RichText;
use App\Support\Settings\SiteSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    app(ContentTypes::class)->register(new ProductsType);
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'uk', 'active' => true], ['code' => 'en', 'active' => true]],
        'content_default_locale' => 'uk',
    ]);

    $this->editor = User::factory()->withRole(SystemRole::Editor->value)->create();
    $this->superAdmin = User::factory()->withRole(SystemRole::SuperAdmin->value)->create();
});

/**
 * Form data of a record with every common key present, as the admin form sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function saveEntryData(array $overrides = []): array
{
    return array_replace([
        'title' => 'Сторінка',
        'slug' => 'storinka',
        'parent_id' => null,
        'author_id' => null,
        'image_id' => null,
        'status' => 'draft',
        'published_at' => null,
        'excerpt' => null,
        'seo_title' => null,
        'seo_description' => null,
        'body' => null,
        'fields' => [],
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $data
 */
function createEntryAs(User $actor, string $type, string $locale, array $data, ?Entry $source = null): Entry
{
    return app(CreateEntry::class)->handle($actor, app(ContentTypes::class)->get($type), $locale, saveEntryData($data), $source);
}

/**
 * @param  array<string, mixed>  $data
 */
function updateEntryAs(User $actor, Entry $entry, array $data): Entry
{
    return app(UpdateEntry::class)->handle($actor, $entry, saveEntryData($data));
}

/**
 * A flat test type with a file field, a gallery, a shared gallery and rich text fields (one shared).
 */
function registerEventsType(): ContentType
{
    $type = new class extends ContentType
    {
        public function key(): string
        {
            return 'events';
        }

        public function label(): string
        {
            return 'Event';
        }

        public function icon(): string
        {
            return 'calendar';
        }

        public function hierarchical(): bool
        {
            return false;
        }

        public function base(string $locale): ?string
        {
            return 'events';
        }

        public function fields(): array
        {
            return [
                MediaFile::make('file'), Gallery::make('photos'), Gallery::make('gallery')->shared(),
                RichText::make('notes'), RichText::make('terms')->shared(),
            ];
        }
    };

    app(ContentTypes::class)->register($type);

    return $type;
}

/**
 * @return array<string, int>
 */
function usagesOf(Entry|EntryGroup $usable): array
{
    return MediaUsage::query()
        ->where('usable_type', $usable->getMorphClass())
        ->where('usable_id', $usable->getKey())
        ->orderBy('field')
        ->orderBy('media_id')
        ->get()
        ->map(fn (MediaUsage $usage): string => "{$usage->field}:{$usage->media_id}")
        ->all();
}

test('creating a page makes its group and path, sets the actor as author, cleans the body and records the media it uses', function () {
    $image = Media::factory()->image()->create();
    $inBody = Media::factory()->image()->create();
    $holder = Entry::factory()->page()->inLocale('uk')->create(['slug' => 'old', 'path' => 'old']);
    EntryRedirect::create(['type' => 'pages', 'locale' => 'uk', 'path' => 'pro-nas', 'entry_id' => $holder->id]);

    $entry = createEntryAs($this->editor, 'pages', 'uk', [
        'title' => 'Про нас',
        'slug' => 'pro-nas',
        'image_id' => $image->id,
        'excerpt' => '  Коротко  ',
        'body' => '<p onclick="steal()">Текст</p><script>alert(1)</script>'
            .'<img data-media-id="'.$inBody->id.'" alt="Фото"><img data-media-id="999999">',
    ]);

    expect($entry->group)->toBeInstanceOf(EntryGroup::class)
        ->and($entry->group->type)->toBe('pages')
        ->and($entry->type)->toBe('pages')
        ->and($entry->locale)->toBe('uk')
        ->and($entry->path)->toBe('pro-nas')
        ->and($entry->author_id)->toBe($this->editor->id)
        ->and($entry->status)->toBe(EntryStatus::Draft)
        ->and($entry->published_at)->toBeNull()
        ->and($entry->excerpt)->toBe('Коротко')
        ->and($entry->body)->toContain('<p>Текст</p>', 'data-media-id="'.$inBody->id.'"')
        ->and($entry->body)->not->toContain('script', 'onclick')
        ->and(usagesOf($entry))->toBe(["body:{$inBody->id}", "image:{$image->id}"])
        ->and(EntryRedirect::query()->where('path', 'pro-nas')->exists())->toBeFalse();
});

test('changing the slug or the parent moves the descendants and keeps the old paths; type and language never change', function () {
    $about = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);
    $team = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Команда', 'slug' => 'team', 'parent_id' => $about->id]);
    $lead = Entry::factory()->childOf($team)->create(['slug' => 'lead']);

    expect($team->path)->toBe('about/team');

    updateEntryAs($this->editor, $about, ['title' => 'Компанія', 'slug' => 'company', 'locale' => 'en', 'type' => 'products']);

    expect($about->fresh())
        ->title->toBe('Компанія')
        ->path->toBe('company')
        ->locale->toBe('uk')
        ->type->toBe('pages')
        ->and($team->fresh()->path)->toBe('company/team')
        ->and($lead->fresh()->path)->toBe('company/team/lead')
        ->and(EntryRedirect::query()->orderBy('path')->pluck('path')->all())->toBe(['about', 'about/team', 'about/team/lead']);

    updateEntryAs($this->editor, $team->fresh(), ['title' => 'Команда', 'slug' => 'team', 'parent_id' => null]);

    expect($team->fresh()->path)->toBe('team')
        ->and($team->fresh()->parent_id)->toBeNull()
        ->and($lead->fresh()->path)->toBe('team/lead');
});

test('a translation joins the group, starts from copyFor() and maps the parent to its translation', function () {
    $servicesUk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Послуги', 'slug' => 'poslugy']);
    $servicesEn = createEntryAs($this->editor, 'pages', 'en', ['title' => 'Services', 'slug' => 'services'], $servicesUk);
    $image = Media::factory()->image()->create();

    $deliveryUk = createEntryAs($this->editor, 'pages', 'uk', [
        'title' => 'Доставка',
        'slug' => 'dostavka',
        'parent_id' => $servicesUk->id,
        'image_id' => $image->id,
        'excerpt' => 'Коротко',
        'seo_title' => 'Доставка SEO',
        'seo_description' => 'Опис',
        'body' => '<p>Текст</p>',
        'status' => 'published',
    ]);

    $copy = $deliveryUk->copyFor('en');

    expect($copy)->toMatchArray([
        'title' => 'Доставка',
        'slug' => '',
        'parent_id' => $servicesEn->id,
        'author_id' => $this->editor->id,
        'image_id' => $image->id,
        'excerpt' => 'Коротко',
        'seo_title' => 'Доставка SEO',
        'seo_description' => 'Опис',
        'body' => '<p>Текст</p>',
        'status' => 'draft',
        'published_at' => null,
    ]);

    $deliveryEn = createEntryAs($this->editor, 'pages', 'en', [...$copy, 'title' => 'Delivery', 'slug' => 'delivery'], $deliveryUk);

    expect($deliveryEn->entry_group_id)->toBe($deliveryUk->entry_group_id)
        ->and($deliveryEn->locale)->toBe('en')
        ->and($deliveryEn->path)->toBe('services/delivery')
        ->and($deliveryEn->status)->toBe(EntryStatus::Draft)
        ->and($deliveryUk->fresh()->translations()->modelKeys())->toBe([$deliveryEn->id])
        ->and(EntryGroup::query()->count())->toBe(2);

    $contactsUk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Контакти', 'slug' => 'kontakty']);
    $mapUk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Мапа', 'slug' => 'mapa', 'parent_id' => $contactsUk->id]);

    expect($mapUk->copyFor('en')['parent_id'])->toBeNull();
});

test('copyFor() copies the per-language fields and carries the shared ones', function () {
    $cover = Media::factory()->image()->create();
    $table = createEntryAs($this->superAdmin, 'products', 'uk', [
        'title' => 'Стіл',
        'slug' => 'stil',
        'fields' => ['price' => '10', 'subtitle' => 'Дуб', 'cover' => $cover->id, 'featured' => true],
    ]);

    expect($table->copyFor('en')['fields'])->toMatchArray([
        'price' => 10,
        'subtitle' => 'Дуб',
        'cover' => $cover->id,
        'featured' => true,
        'released' => '',
        'photos' => [],
    ]);
});

test('creating a translation the group already has returns that record unchanged, also from the trash', function () {
    $uk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);
    $en = createEntryAs($this->editor, 'pages', 'en', ['title' => 'About', 'slug' => 'about'], $uk);

    $again = createEntryAs($this->editor, 'pages', 'en', ['title' => 'Other', 'slug' => 'other'], $uk);

    expect($again->id)->toBe($en->id)
        ->and($again->title)->toBe('About')
        ->and(Entry::query()->count())->toBe(2);

    $en->delete();

    expect(createEntryAs($this->editor, 'pages', 'en', ['title' => 'Other', 'slug' => 'other'], $uk)->id)->toBe($en->id)
        ->and(Entry::withTrashed()->count())->toBe(2);
});

test('a create that loses the race for a translation returns the record that won', function (string $slug) {
    $uk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);

    // The competing request inserts its record right after this one looked for an existing translation.
    $winner = null;
    DB::listen(function (QueryExecuted $query) use (&$winner, $uk): void {
        $sql = strtolower($query->sql);

        if ($winner === null && str_starts_with($sql, 'select') && str_contains($sql, 'entry_group_id') && str_contains($sql, 'locale')) {
            $winner = false;
            $winner = Entry::factory()->translationOf($uk, 'en')->create(['title' => 'Winner', 'slug' => 'about-us', 'path' => 'about-us']);
        }
    });

    $result = createEntryAs($this->editor, 'pages', 'en', ['title' => 'Loser', 'slug' => $slug], $uk);

    expect($winner)->toBeInstanceOf(Entry::class)
        ->and($result->id)->toBe($winner->id)
        ->and($result->title)->toBe('Winner')
        ->and(Entry::query()->where('locale', 'en')->count())->toBe(1);
})->with([
    'the same slug (fails validation)' => 'about-us',
    'another slug (fails on the unique index)' => 'about-them',
]);

test('publishing without a date publishes now; a future date schedules; the date is read in the site timezone', function () {
    $this->travelTo(now()->setDate(2026, 7, 1)->setTime(12, 0));
    config(['admin.timezone' => 'Europe/Kyiv']);

    $now = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Зараз', 'slug' => 'zaraz', 'status' => 'published']);

    expect($now->fresh()->published_at->equalTo(now()))->toBeTrue()
        ->and($now->fresh()->isPublic())->toBeTrue();

    $later = createEntryAs($this->editor, 'pages', 'uk', [
        'title' => 'Пізніше',
        'slug' => 'piznishe',
        'status' => 'published',
        'published_at' => '2026-07-02T15:30',
    ]);

    expect($later->fresh()->published_at->utc()->format('Y-m-d H:i'))->toBe('2026-07-02 12:30')
        ->and($later->fresh()->state())->toBe(EntryState::Scheduled);

    $draft = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Чернетка', 'slug' => 'chernetka']);
    updateEntryAs($this->editor, $draft, ['title' => 'Чернетка', 'slug' => 'chernetka', 'status' => 'published']);

    expect($draft->fresh()->published_at->equalTo(now()))->toBeTrue();
});

test('a shared field written from one translation reads the same from the other', function () {
    $uk = createEntryAs($this->superAdmin, 'products', 'uk', [
        'title' => 'Стіл',
        'slug' => 'stil',
        'fields' => ['price' => '10', 'subtitle' => 'Дуб'],
    ]);
    $en = createEntryAs($this->superAdmin, 'products', 'en', [
        'title' => 'Table',
        'slug' => 'table',
        'fields' => ['price' => '12.5', 'subtitle' => 'Oak'],
    ], $uk);

    expect($uk->fresh()->fieldValue('price'))->toBe(12.5)
        ->and($uk->fresh()->fieldValue('subtitle'))->toBe('Дуб')
        ->and($en->fresh()->fieldValue('subtitle'))->toBe('Oak')
        ->and($en->fresh()->fields)->not->toHaveKey('price')
        ->and($uk->group->fresh()->shared)->toBe(['price' => 12.5]);

    updateEntryAs($this->superAdmin, $uk->fresh(), ['title' => 'Стіл', 'slug' => 'stil', 'fields' => ['price' => '9', 'subtitle' => 'Дуб']]);

    expect($en->fresh()->fieldValue('price'))->toBe(9);
});

test('a malformed date in a date field is a field error, never an exception', function () {
    expect(fn () => createEntryAs($this->superAdmin, 'products', 'uk', [
        'title' => 'Стіл',
        'slug' => 'stil',
        'fields' => ['price' => '10', 'released' => '2026-13-45'],
    ]))->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('fields.released'));
});

test('a tampered value is a field error and nothing is stored', function (string $case, string $errorKey) {
    $parent = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Батько', 'slug' => 'batko']);
    $child = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Дитина', 'slug' => 'dytyna', 'parent_id' => $parent->id]);
    $grandchild = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Онук', 'slug' => 'onuk', 'parent_id' => $child->id]);
    $english = createEntryAs($this->editor, 'pages', 'en', ['title' => 'English', 'slug' => 'english']);
    $product = createEntryAs($this->superAdmin, 'products', 'uk', ['title' => 'Стіл', 'slug' => 'stil', 'fields' => ['price' => '1']]);
    $trashed = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Кошик', 'slug' => 'koshyk']);
    $trashed->delete();
    $image = Media::factory()->image()->create();
    $document = Media::factory()->document()->create();
    $stranger = User::factory()->withRole(SystemRole::Author->value)->create();
    $creatorOnly = User::factory()->withPermissions(['pages.create'])->create();

    $page = fn (array $data) => fn () => createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Нова', 'slug' => 'nova', ...$data]);
    $productWith = fn (array $fields) => fn () => createEntryAs($this->superAdmin, 'products', 'uk', [
        'title' => 'Крісло', 'slug' => 'krislo', 'fields' => ['price' => '2', ...$fields],
    ]);

    $action = match ($case) {
        'a language that is not on the site' => fn () => createEntryAs($this->editor, 'pages', 'pl', ['title' => 'Nowa', 'slug' => 'nowa']),
        'a parent in another language' => $page(['parent_id' => $english->id]),
        'a parent of another type' => $page(['parent_id' => $product->id]),
        'a parent that does not exist' => $page(['parent_id' => 999999]),
        'the record itself as its parent' => fn () => updateEntryAs($this->editor, $parent, ['title' => 'Батько', 'slug' => 'batko', 'parent_id' => $parent->id]),
        'a descendant as the parent' => fn () => updateEntryAs($this->editor, $parent, ['title' => 'Батько', 'slug' => 'batko', 'parent_id' => $grandchild->id]),
        'a trashed parent' => $page(['parent_id' => $trashed->id]),
        'a parent on a flat type' => fn () => createEntryAs($this->superAdmin, 'products', 'uk', [
            'title' => 'Крісло', 'slug' => 'krislo', 'parent_id' => $product->id, 'fields' => ['price' => '2'],
        ]),
        'an author who may not create the type' => $page(['author_id' => $stranger->id]),
        'publishing by a creator without edit' => fn () => createEntryAs($creatorOnly, 'pages', 'uk', [
            'title' => 'Нова', 'slug' => 'nova', 'status' => 'published',
        ]),
        'scheduling by a creator without edit' => fn () => createEntryAs($creatorOnly, 'pages', 'uk', [
            'title' => 'Нова', 'slug' => 'nova', 'status' => 'published', 'published_at' => now()->addWeek()->format('Y-m-d\TH:i'),
        ]),
        'an excerpt over 1000 characters' => $page(['excerpt' => str_repeat('я', 1001)]),
        'an image that is a document' => $page(['image_id' => $document->id]),
        'an image that does not exist' => $page(['image_id' => 999999]),
        'a gallery with a missing file' => $productWith(['photos' => [$image->id, 999999]]),
        'a document in an image gallery' => $productWith(['photos' => [$document->id]]),
        'a file field with a missing file' => $productWith(['cover' => 999999]),
        'a body over the limit' => $page(['body' => '<p>'.str_repeat('a', 1_000_000).'</p>']),
    };

    $snapshot = fn (): array => [
        Entry::withTrashed()->orderBy('id')->get()->map->only(['id', 'title', 'slug', 'path', 'parent_id', 'author_id', 'image_id', 'body'])->all(),
        EntryGroup::query()->count(),
        MediaUsage::query()->count(),
        EntryRedirect::query()->count(),
    ];
    $before = $snapshot();

    expect($action)->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($errorKey))
        ->and($snapshot())->toBe($before);
})->with([
    ['a language that is not on the site', 'locale'],
    ['a parent in another language', 'parent_id'],
    ['a parent of another type', 'parent_id'],
    ['a parent that does not exist', 'parent_id'],
    ['the record itself as its parent', 'parent_id'],
    ['a descendant as the parent', 'parent_id'],
    ['a trashed parent', 'parent_id'],
    ['a parent on a flat type', 'parent_id'],
    ['an author who may not create the type', 'author_id'],
    ['publishing by a creator without edit', 'status'],
    ['scheduling by a creator without edit', 'status'],
    ['an excerpt over 1000 characters', 'excerpt'],
    ['an image that is a document', 'image_id'],
    ['an image that does not exist', 'image_id'],
    ['a gallery with a missing file', 'fields.photos'],
    ['a document in an image gallery', 'fields.photos'],
    ['a file field with a missing file', 'fields.cover'],
    ['a body over the limit', 'body'],
]);

test('each action needs the type\'s permission', function () {
    $page = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);
    $outsider = User::factory()->withRole(SystemRole::Author->value)->create();
    $creatorOnly = User::factory()->withPermissions(['pages.create'])->create();

    expect(fn () => createEntryAs($outsider, 'pages', 'uk', ['title' => 'Нова', 'slug' => 'nova']))->toThrow(AuthorizationException::class)
        ->and(fn () => createEntryAs($outsider, 'pages', 'en', ['title' => 'About', 'slug' => 'about'], $page))->toThrow(AuthorizationException::class)
        ->and(fn () => updateEntryAs($outsider, $page, ['title' => 'Інша', 'slug' => 'about']))->toThrow(AuthorizationException::class)
        ->and(fn () => updateEntryAs($creatorOnly, $page, ['title' => 'Інша', 'slug' => 'about']))->toThrow(AuthorizationException::class)
        ->and(fn () => createEntryAs($this->editor, 'products', 'uk', ['title' => 'Стіл', 'slug' => 'stil', 'fields' => ['price' => '1']]))->toThrow(AuthorizationException::class)
        ->and(fn () => createEntryAs($creatorOnly, 'pages', 'uk', ['title' => 'Нова', 'slug' => 'nova', 'status' => 'published']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('status'))
        ->and(Entry::query()->count())->toBe(1)
        ->and($page->fresh()->title)->toBe('Про нас');

    expect(createEntryAs($creatorOnly, 'pages', 'uk', ['title' => 'Нова', 'slug' => 'nova'])->author_id)->toBe($creatorOnly->id);
});

test('the policy checks the type\'s own permissions', function () {
    $user = User::factory()->withPermissions(['pages.view', 'pages.edit'])->create();
    $page = Entry::factory()->page()->create();
    $product = Entry::factory()->create(['type' => 'products']);

    expect($user->can('viewAny', [Entry::class, 'pages']))->toBeTrue()
        ->and($user->can('viewAny', [Entry::class, 'products']))->toBeFalse()
        ->and($user->can('create', [Entry::class, 'pages']))->toBeFalse()
        ->and($user->can('view', $page))->toBeTrue()
        ->and($user->can('update', $page))->toBeTrue()
        ->and($user->can('restore', $page))->toBeTrue()
        ->and($user->can('preview', $page))->toBeTrue()
        ->and($user->can('delete', $page))->toBeFalse()
        ->and($user->can('forceDelete', $page))->toBeFalse()
        ->and($user->can('view', $product))->toBeFalse();
});

test('authors are the users who may create the type, super admins included', function () {
    $direct = User::factory()->withPermissions(['pages.create'])->create(['name' => 'Direct']);
    User::factory()->withRole(SystemRole::Author->value)->create();
    User::factory()->withPermissions(['products.create'])->create();
    User::factory()->withPermissions(['pages.view'])->create();

    expect(array_keys(EntryAuthors::options(app(ContentTypes::class)->get('pages'))))
        ->toEqualCanonicalizing([$this->editor->id, $this->superAdmin->id, $direct->id])
        ->and(EntryAuthors::options(app(ContentTypes::class)->get('pages'))[$direct->id])->toBe('Direct');
});

test('the current author may stay even after losing the permission', function () {
    $writer = User::factory()->withPermissions(['pages.create'])->create();
    $page = createEntryAs($writer, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);
    $writer->revokePermissionTo('pages.create');

    updateEntryAs($this->editor, $page, ['title' => 'Про нас!', 'slug' => 'about', 'author_id' => $writer->id]);

    expect($page->fresh())->title->toBe('Про нас!')->author_id->toBe($writer->id);
});

test('media fields record their usages on the record, shared ones on the group, and forgetting a file clears each kind', function () {
    registerEventsType();
    [$a, $b] = Media::factory()->image()->count(2)->create()->all();
    $document = Media::factory()->document()->create();

    $event = createEntryAs($this->superAdmin, 'events', 'uk', [
        'title' => 'Подія',
        'slug' => 'podiia',
        'image_id' => $a->id,
        'body' => '<p>Текст</p><img data-media-id="'.$a->id.'" alt="A"><img data-media-id="'.$b->id.'" alt="B">',
        'fields' => ['file' => $document->id, 'photos' => [$a->id, $b->id], 'gallery' => [$b->id, $a->id]],
    ]);
    $group = $event->group;

    expect(usagesOf($event))->toBe([
        "body:{$a->id}", "body:{$b->id}", "fields.file:{$document->id}", "fields.photos:{$a->id}", "fields.photos:{$b->id}", "image:{$a->id}",
    ])
        ->and(usagesOf($group))->toBe(["shared.gallery:{$a->id}", "shared.gallery:{$b->id}"])
        ->and(MediaUsage::query()->where('field', 'image')->first()->label())->toBe('Event: Подія')
        ->and(MediaUsage::query()->where('field', 'shared.gallery')->first()->label())->toBe('Event: Подія (all languages)');

    MediaUsage::forgetAll([$a]);
    $event->refresh();

    expect($event->image_id)->toBeNull()
        ->and($event->body)->not->toContain('data-media-id="'.$a->id.'"')
        ->and($event->body)->toContain('data-media-id="'.$b->id.'"')
        ->and($event->fields['photos'])->toBe([$b->id])
        ->and($event->fields['file'])->toBe($document->id)
        ->and($group->fresh()->shared['gallery'])->toBe([$b->id]);

    MediaUsage::forgetAll([$document]);

    expect($event->fresh()->fields['file'])->toBeNull();
});

test('rich text fields are cleaned like the body and their images are usages that a deleted file leaves', function () {
    registerEventsType();
    [$a, $b] = Media::factory()->image()->count(2)->create()->all();
    $hostile = '<p onclick="x()">Hi<script>alert(1)</script></p><a href="javascript:alert(1)">l</a><img src="x" onerror="y()" data-media-id="';

    $event = createEntryAs($this->superAdmin, 'events', 'uk', [
        'title' => 'Подія',
        'slug' => 'podiia',
        'fields' => [
            'notes' => $hostile.$a->id.'" alt="A"><img data-media-id="999999" alt="gone">',
            'terms' => $hostile.$b->id.'" alt="B">',
        ],
    ]);
    $group = $event->group;

    expect($event->fields['notes'])->toBe('<p>Hi</p><a>l</a><img data-media-id="'.$a->id.'" alt="A"><img data-media-id="999999" alt="gone">')
        ->and($group->shared['terms'])->toBe('<p>Hi</p><a>l</a><img data-media-id="'.$b->id.'" alt="B">')
        ->and(usagesOf($event))->toBe(["fields.notes:{$a->id}"])
        ->and(usagesOf($group))->toBe(["shared.terms:{$b->id}"]);

    MediaUsage::forgetAll([$a, $b]);

    expect($event->fresh()->fields['notes'])->toBe('<p>Hi</p><a>l</a><img data-media-id="999999" alt="gone">')
        ->and($group->fresh()->shared['terms'])->toBe('<p>Hi</p><a>l</a>');

    $empty = createEntryAs($this->superAdmin, 'events', 'en', ['title' => 'Empty', 'slug' => 'empty', 'fields' => ['notes' => '<p></p>']]);
    expect($empty->fields['notes'])->toBeNull();
});

test('a rich text field longer than the body limit is a field error', function () {
    registerEventsType();

    expect(fn () => createEntryAs($this->superAdmin, 'events', 'uk', [
        'title' => 'Подія', 'slug' => 'podiia', 'fields' => ['notes' => '<p>'.str_repeat('a', EntryInput::BODY_MAX_BYTES).'</p>'],
    ]))->toThrow(ValidationException::class);
});

test('a creator without edit translates from copyFor() and becomes the author; another author they name is ignored', function () {
    $creatorOnly = User::factory()->withPermissions(['pages.create'])->create();
    $uk = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'pro-nas', 'status' => 'published']);

    $copy = $uk->copyFor('en');

    expect($copy['author_id'])->toBe($this->editor->id);

    $en = createEntryAs($creatorOnly, 'pages', 'en', [...$copy, 'title' => 'About', 'slug' => 'about'], $uk);
    $own = createEntryAs($creatorOnly, 'pages', 'uk', ['title' => 'Нова', 'slug' => 'nova', 'author_id' => $this->editor->id]);

    expect($en->entry_group_id)->toBe($uk->entry_group_id)
        ->and($en->author_id)->toBe($creatorOnly->id)
        ->and($en->status)->toBe(EntryStatus::Draft)
        ->and($own->author_id)->toBe($creatorOnly->id);
});

test('a slug or parent change that would push a subpage past 255 characters is refused, with nothing stored', function () {
    $top = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'А', 'slug' => 'a']);
    $other = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Х', 'slug' => 'x']);
    $child = Entry::factory()->childOf($top)->create(['slug' => str_repeat('c', 253)]);

    expect(strlen($child->path))->toBe(255);

    expect(fn () => updateEntryAs($this->editor, $top, ['title' => 'А', 'slug' => 'ab']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('slug')->not->toHaveKey('parent_id'))
        ->and(fn () => updateEntryAs($this->editor, $top->fresh(), ['title' => 'А', 'slug' => 'a', 'parent_id' => $other->id]))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('parent_id')->not->toHaveKey('slug'))
        ->and($top->fresh()->path)->toBe('a')
        ->and($child->fresh()->path)->toBe($child->path)
        ->and(EntryRedirect::query()->count())->toBe(0);

    updateEntryAs($this->editor, $top->fresh(), ['title' => 'А', 'slug' => 'b']);

    expect($child->fresh()->path)->toBe('b/'.str_repeat('c', 253));
});

test('a record in the trash is not updated', function () {
    $page = createEntryAs($this->editor, 'pages', 'uk', ['title' => 'Про нас', 'slug' => 'about']);
    $page->delete();

    expect(fn () => updateEntryAs($this->editor, $page, ['title' => 'Інша', 'slug' => 'about']))
        ->toThrow(ProtectedActionException::class)
        ->and(Entry::withTrashed()->find($page->id)->title)->toBe('Про нас');
});
