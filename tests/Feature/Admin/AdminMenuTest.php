<?php

use App\Models\User;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Hata\Support\Content\Types\TagsType;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Blade;
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

it('skips an item whose route does not exist', function () {
    app(AdminMenu::class)
        ->group('shop', 'Shop', 50)
        ->add(new AdminMenuItem('shop.ghost', 'Ghost', 'star', 'admin.shop.nowhere', null, 'shop'))
        ->add(new AdminMenuItem('shop.real', 'Real', 'star', 'admin.dashboard', null, 'shop'));
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $sidebar = renderedSidebar($this->get(route('admin.dashboard')));

    expect(collect($sidebar['Shop'])->pluck('label')->all())->toBe(['Real']);
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
