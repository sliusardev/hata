<?php

use App\Models\User;
use Hata\Models\Entry;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => seedRolesAndPermissions());

/**
 * The sidebar's groups as rendered: heading => list of [label, href path, icon path signature, current].
 *
 * @return array<string, list<array{label: string, path: string, icon: string, current: bool}>>
 */
function renderedSidebar(TestResponse $response): array
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
    $xpath = new DOMXPath($document);
    $groups = [];

    foreach ($xpath->query('//*[@data-flux-sidebar-group]') as $group) {
        $heading = trim($xpath->query('./div[1]', $group)->item(0)->textContent);
        $items = [];

        foreach ($xpath->query('.//a[@data-flux-sidebar-item]', $group) as $link) {
            $svg = $xpath->query('.//svg', $link)->item(0);
            $items[] = [
                'label' => trim($xpath->query('.//*[@data-content]', $link)->item(0)->textContent),
                'path' => parse_url($link->getAttribute('href'), PHP_URL_PATH),
                'icon' => iconSignature($svg),
                'current' => $link->hasAttribute('data-current'),
            ];
        }

        $groups[$heading] = $items;
    }

    return $groups;
}

function iconSignature(DOMElement $svg): string
{
    $paths = [];

    foreach ($svg->getElementsByTagName('path') as $path) {
        $paths[] = $path->getAttribute('d');
    }

    return ($svg->getAttribute('fill') === 'currentColor' ? 'solid:' : 'outline:').md5(implode('|', $paths));
}

function expectedIcon(string $name, bool $solid = false): string
{
    $html = Blade::render('<flux:icon :icon="$name" :variant="$variant" />', ['name' => $name, 'variant' => $solid ? 'solid' : 'outline']);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);

    return iconSignature($document->getElementsByTagName('svg')->item(0));
}

function sidebarItem(string $label, string $path, string $icon, bool $current = false): array
{
    return ['label' => $label, 'path' => $path, 'icon' => expectedIcon($icon, $current), 'current' => $current];
}

it('shows hata\'s sections to a super admin in today\'s order', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    // an explicit list, written down from the sidebar as it was before the registry
    expect(renderedSidebar($this->get(route('admin.dashboard'))))->toBe([
        'Platform' => [sidebarItem('Dashboard', '/admin', 'home', current: true)],
        'Content' => [
            sidebarItem('Pages', '/admin/content/pages', 'document-text'),
            sidebarItem('Media', '/admin/media', 'photo'),
        ],
        'Administration' => [
            sidebarItem('Users', '/admin/users', 'users'),
            sidebarItem('Roles', '/admin/roles', 'shield-check'),
            sidebarItem('Site settings', '/admin/settings', 'adjustments-horizontal'),
            sidebarItem('Maintenance', '/admin/maintenance', 'wrench-screwdriver'),
        ],
        'Account' => [sidebarItem('My profile', '/settings/profile', 'user-circle')],
    ]);
});

it('marks the current item of each section', function (string $url, string $label) {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $current = collect(renderedSidebar($this->get($url)))->flatten(1)->where('current', true)->pluck('label')->all();

    expect($current)->toBe([$label]);
})->with([
    'a content list' => ['/admin/content/pages', 'Pages'],
    'a media page' => ['/admin/media', 'Media'],
    'a users page' => ['/admin/users/create', 'Users'],
    'a settings page' => ['/admin/settings', 'Site settings'],
    'the profile' => ['/settings/profile', 'My profile'],
    'the language page' => ['/settings/language', 'My profile'],
    'the appearance page' => ['/settings/appearance', 'My profile'],
]);

it('hides items the user may not open', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());

    $sidebar = renderedSidebar($this->get(route('admin.dashboard')));

    expect(array_keys($sidebar))->toBe(['Platform', 'Content', 'Account'])
        ->and(collect($sidebar['Content'])->pluck('label')->all())->toBe(['Media']);
});

it('shows a user without admin access only the account group', function () {
    $this->actingAs(User::factory()->create());

    expect(array_keys(renderedSidebar($this->get(route('profile.edit')))))->toBe(['Account']);
});

it('adds a module item and group', function () {
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.orders', 'Orders', 'shopping-cart', 'admin.dashboard', 'admin.access', 'shop'));
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $sidebar = renderedSidebar($this->get(route('admin.dashboard')));

    expect(array_keys($sidebar))->toBe(['Platform', 'Shop', 'Content', 'Administration', 'Account'])
        ->and($sidebar['Shop'][0]['label'])->toBe('Orders')
        ->and($sidebar['Shop'][0]['path'])->toBe('/admin');
});

it('runs a closure permission and removes an item by key', function () {
    $menu = app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.a', 'Allowed', 'star', 'admin.dashboard', fn ($user) => true, 'shop'))
        ->add(new AdminMenuItem('shop.b', 'Denied', 'star', 'admin.dashboard', fn ($user) => false, 'shop', 20))
        ->remove('users');
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $sidebar = renderedSidebar($this->get(route('admin.dashboard')));

    expect(collect($sidebar['Shop'])->pluck('label')->all())->toBe(['Allowed'])
        ->and(collect($sidebar['Administration'])->pluck('label')->all())->not->toContain('Users');
});

it('drops an empty group', function () {
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.orders', 'Orders', 'shopping-cart', 'admin.dashboard', 'orders.manage', 'shop'));
    $user = User::factory()->withPermissions(['admin.access'])->create();

    $groups = app(AdminMenu::class)->for($user);

    expect($groups)->not->toHaveKey('shop')
        ->and(array_keys($groups))->toBe(['platform', 'account']);
});

function inProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.debug' => false]);
}

it('throws for an item whose route is not defined outside production', function () {
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.ghost', 'Ghost', 'star', 'admin.shop.nowhere', null, 'shop'));
    $user = User::factory()->withRole('super-admin')->create();

    expect(fn () => app(AdminMenu::class)->for($user))
        ->toThrow(LogicException::class, 'shop.ghost');
});

it('skips and logs an item whose route is not defined in production', function () {
    inProduction();
    Log::spy();
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.ghost', 'Ghost', 'star', 'admin.shop.nowhere', null, 'shop'))
        ->add(new AdminMenuItem('shop.real', 'Real', 'star', 'admin.dashboard', null, 'shop'));
    $user = User::factory()->withRole('super-admin')->create();

    $items = app(AdminMenu::class)->for($user)['shop']['items'];

    expect(collect($items)->pluck('key')->all())->toBe(['shop.real']);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'shop.ghost'));
});

it('skips a broken item in production with app.debug on, and logs it once per process', function () {
    inProduction();
    config(['app.debug' => true]);
    Log::spy();
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.debug-ghost', 'Ghost', 'star', 'admin.shop.nowhere', null, 'shop'))
        ->add(new AdminMenuItem('shop.real', 'Real', 'star', 'admin.dashboard', null, 'shop'));
    $user = User::factory()->withRole('super-admin')->create();

    app(AdminMenu::class)->for($user);
    $items = app(AdminMenu::class)->for($user)['shop']['items'];

    expect(collect($items)->pluck('key')->all())->toBe(['shop.real']);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'shop.debug-ghost'));
});

it('builds relative item addresses, so no request\'s host is remembered', function () {
    $item = new AdminMenuItem('shop.orders', 'Orders', 'star', 'admin.dashboard', null, 'shop');

    expect($item->url())->toBe(route('admin.dashboard', absolute: false))
        ->and($item->url())->toStartWith('/');
});

it('does not hide a wrong route parameter', function () {
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.bad', 'Bad', 'star', 'admin.content.index', null, 'shop'));
    $user = User::factory()->withRole('super-admin')->create();

    expect(fn () => app(AdminMenu::class)->for($user))->toThrow(UrlGenerationException::class);
});

it('throws for an item in a group that was never declared', function () {
    app(AdminMenu::class)->add(new AdminMenuItem('shop.orders', 'Orders', 'star', 'admin.dashboard', null, 'shop'));
    $user = User::factory()->withRole('super-admin')->create();

    expect(fn () => app(AdminMenu::class)->for($user))->toThrow(LogicException::class, 'shop.orders');
});

it('throws for a parent that does not exist or a parent cycle', function (array $items) {
    $menu = app(AdminMenu::class)->group('shop', 'Shop', 50);

    foreach ($items as [$key, $parent]) {
        $menu->add(new AdminMenuItem($key, $key, 'star', 'admin.dashboard', null, 'shop', parent: $parent));
    }

    expect(fn () => $menu->for(User::factory()->withRole('super-admin')->create()))
        ->toThrow(LogicException::class, $items[0][0]);
})->with([
    'a missing parent' => [[['shop.child', 'shop.nope']]],
    'a cycle' => [[['shop.a', 'shop.b'], ['shop.b', 'shop.a']]],
]);

it('skips the broken items and logs in production', function () {
    inProduction();
    Log::spy();
    app(AdminMenu::class)
        ->add(new AdminMenuItem('stray', 'Stray', 'star', 'admin.dashboard', null, 'nowhere'))
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.a', 'A', 'star', 'admin.dashboard', null, 'shop', parent: 'shop.b'))
        ->add(new AdminMenuItem('shop.b', 'B', 'star', 'admin.dashboard', null, 'shop', parent: 'shop.a'))
        ->add(new AdminMenuItem('shop.orphan', 'Orphan', 'star', 'admin.dashboard', null, 'shop', parent: 'shop.nope'));

    $groups = app(AdminMenu::class)->for(User::factory()->withRole('super-admin')->create());

    expect(collect($groups['shop']['items'])->pluck('key')->all())->toBe(['shop.orphan'])
        ->and($groups)->not->toHaveKey('nowhere');
    Log::shouldHaveReceived('warning')->times(4);
});

it('shows each kind of user only the items they may open', function (callable $user, array $expected) {
    $this->actingAs($user());

    $labels = collect(renderedSidebar($this->get(route('profile.edit'))))->flatten(1)->pluck('label')->all();

    expect($labels)->toBe($expected);
})->with([
    'super admin' => [
        fn () => User::factory()->withRole('super-admin')->create(),
        ['Dashboard', 'Pages', 'Media', 'Users', 'Roles', 'Site settings', 'Maintenance', 'My profile'],
    ],
    'admin (maintenance is reserved)' => [
        fn () => User::factory()->withRole('admin')->create(),
        ['Dashboard', 'Pages', 'Media', 'Users', 'Roles', 'Site settings', 'My profile'],
    ],
    'users.view without roles.view' => [
        fn () => User::factory()->withPermissions(['admin.access', 'users.view'])->create(),
        ['Dashboard', 'Users', 'My profile'],
    ],
    'admin.access and media.view only' => [
        fn () => User::factory()->withPermissions(['admin.access', 'media.view'])->create(),
        ['Dashboard', 'Media', 'My profile'],
    ],
]);

it('marks only the second type as current on its own pages and vice versa', function () {
    app(ContentTypes::class)->register(new PostsType);
    $this->actingAs(User::factory()->withRole('super-admin')->create());
    $entry = Entry::factory()->create(['type' => 'posts', 'title' => 'Chair', 'slug' => 'chair', 'path' => 'chair']);
    $page = Entry::factory()->create(['type' => 'pages', 'title' => 'About', 'slug' => 'about', 'path' => 'about']);

    foreach ([
        'Posts' => [route('admin.content.index', 'posts'), route('admin.content.create', 'posts'), route('admin.content.edit', ['type' => 'posts', 'entry' => $entry])],
        'Pages' => [route('admin.content.index', 'pages'), route('admin.content.create', 'pages'), route('admin.content.edit', ['type' => 'pages', 'entry' => $page])],
    ] as $label => $urls) {
        foreach ($urls as $url) {
            $current = collect(renderedSidebar($this->get($url)))->flatten(1)->where('current', true)->pluck('label')->all();

            expect($current)->toBe([$label], $url);
        }
    }
});

it('nests taxonomies under their type', function () {
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType)->register(new TagsType);
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $content = renderedSidebar($this->get(route('admin.dashboard')))['Content'];

    expect(collect($content)->pluck('label')->all())->toBe(['Pages', 'Posts', 'Categories', 'Tags', 'Media']);

    $items = app(AdminMenu::class)->for(auth()->user())['content']['items'];
    $byKey = collect($items)->keyBy->key;

    expect($byKey['content.categories']->parent)->toBe('content.posts')
        ->and($byKey['content.tags']->parent)->toBe('content.posts');
});

it('hides a type that is switched off together with its taxonomies', function () {
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType)->register(new TagsType);
    app(SiteSettings::class)->put(['content_disabled_types' => ['posts']]);
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $labels = collect(renderedSidebar($this->get(route('admin.dashboard')))['Content'])->pluck('label')->all();

    expect($labels)->toBe(['Pages', 'Media']);
});

it('remove hides a lazy item', function () {
    app(ContentTypes::class)->register(new PostsType);
    app(AdminMenu::class)->remove('content.posts');
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $content = renderedSidebar($this->get(route('admin.dashboard')))['Content'];

    expect(collect($content)->pluck('label')->all())->not->toContain('Posts');
});

it('does not treat a modify of an absent lazy item as a mistake', function () {
    app(ContentTypes::class)->register(new PostsType);
    app(SiteSettings::class)->put(['content_disabled_types' => ['posts']]);
    app(AdminMenu::class)->modify('content.posts', fn (AdminMenuItem $item) => $item->with(order: 1));
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $this->get(route('admin.dashboard'))->assertOk();
});

it('moves an item by modify', function () {
    app(AdminMenu::class)->modify('media', fn (AdminMenuItem $item) => $item->with(order: 1));
    $admin = User::factory()->withRole('super-admin')->create();

    $keys = collect(app(AdminMenu::class)->for($admin)['content']['items'])->pluck('key')->all();

    expect($keys[0])->toBe('media');
});

it('makes groups collapsible and changes them with modifyGroup', function () {
    $admin = User::factory()->withRole('super-admin')->create();

    expect(app(AdminMenu::class)->for($admin)['content']['collapsible'])->toBeTrue()
        ->and(app(AdminMenu::class)->for($admin)['platform']['collapsible'])->toBeFalse();

    app(AdminMenu::class)->modifyGroup('content', fn (array $group) => [...$group, 'collapsible' => false])
        ->modifyGroup('missing', fn (array $group) => $group);

    expect(app(AdminMenu::class)->for($admin)['content']['collapsible'])->toBeFalse();
});

it('lets a provider that boots before Hata remove one of Hata\'s items', function () {
    $this->app->register(new class($this->app) extends ServiceProvider
    {
        public function boot(): void
        {
            $this->app->make(AdminMenu::class)->remove('media');
        }
    });
    $admin = User::factory()->withRole('super-admin')->create();

    expect(collect(app(AdminMenu::class)->for($admin)['content']['items'] ?? [])->pluck('key')->all())->not->toContain('media');
});

it('works out the lazy providers once per for()', function () {
    $calls = 0;
    app(AdminMenu::class)->lazy(function () use (&$calls): array {
        $calls++;

        return [];
    });

    app(AdminMenu::class)->for(User::factory()->withRole('super-admin')->create());

    expect($calls)->toBe(1);
});

it('shows a content type whose key has an underscore or a stray hyphen', function (string $key) {
    app(ContentTypes::class)->register(new class($key) extends PostsType
    {
        public function __construct(private string $typeKey) {}

        public function key(): string
        {
            return $this->typeKey;
        }
    });
    $admin = User::factory()->withRole('super-admin')->create();
    $this->actingAs($admin);

    $keys = collect(app(AdminMenu::class)->for($admin)['content']['items'])->pluck('key')->all();
    $response = $this->get(route('admin.dashboard'));

    expect($keys)->toContain("content.{$key}");
    $response->assertOk()->assertSee(route('admin.content.index', $key, absolute: false), false);
})->with(['case_studies', 'case-studies-', 'case--studies', '-case']);
