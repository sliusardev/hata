<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\AdminAssets;
use Hata\Support\Admin\AdminMenu;
use Hata\Support\Admin\AdminTopbar;
use Hata\Support\Admin\SettingsSections;
use Hata\Support\Admin\TopbarItem;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedRolesAndPermissions());

function topbarHtml($response): string
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
    $bar = (new DOMXPath($document))->query('//*[@data-test="admin-topbar"]')->item(0);

    return $bar === null ? '' : $document->saveHTML($bar);
}

it('shows the view site link in a new tab in the desktop top bar', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $html = topbarHtml($this->get(route('admin.dashboard')));

    expect($html)->toContain('data-test="admin-topbar"')
        ->and($html)->toMatch('~<a[^>]*href="/"[^>]*target="_blank"|<a[^>]*target="_blank"[^>]*href="/"~');
});

it('keeps the sidebar view site link for mobile only', function () {
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8"?>'.$this->get(route('admin.dashboard'))->getContent());
    $nav = (new DOMXPath($document))->query('//a[@data-flux-sidebar-item][@target="_blank"]/ancestor::nav[1]')->item(0);

    expect($nav)->not->toBeNull()->and($nav->getAttribute('class'))->toContain('lg:hidden');
});

it('lets a site add a link and a component to the top bar', function () {
    Blade::anonymousComponentPath(__DIR__.'/../../fixtures/components');
    Admin::topbar()
        ->add(TopbarItem::link('docs', 'Docs-Link', 'book-open', url: 'https://example.com', side: 'start'))
        ->add(TopbarItem::component('note', 'test-topbar-note'));
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $html = topbarHtml($this->get(route('admin.dashboard')));

    expect($html)->toContain('Docs-Link')->toContain('https://example.com')->toContain('NOTE-COMPONENT')
        ->and(strpos($html, 'Docs-Link'))->toBeLessThan(strpos($html, 'NOTE-COMPONENT'))
        ->and(strpos($html, 'View site'))->toBeLessThan(strpos($html, 'NOTE-COMPONENT'));
});

it('hides top bar items from users without the permission', function () {
    Admin::topbar()->add(TopbarItem::link('secret', 'Secret-Link', 'lock-closed', url: '/x', permission: 'users.view'));

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    expect(topbarHtml($this->get(route('admin.dashboard'))))->not->toContain('Secret-Link');

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());
    expect(topbarHtml($this->get(route('admin.dashboard'))))->toContain('Secret-Link');
});

it('treats an undefined route as a registration mistake', function () {
    Admin::topbar()->add(TopbarItem::link('ghost', 'Ghost', 'bolt', route: 'no.such.route'));
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    $this->withoutExceptionHandling();

    try {
        $this->get(route('admin.dashboard'));
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
        while ($thrown->getPrevious() !== null && ! $thrown instanceof LogicException) {
            $thrown = $thrown->getPrevious();
        }
    }

    expect($thrown)->toBeInstanceOf(LogicException::class)->and($thrown->getMessage())->toContain('ghost');
});

it('treats a link without a target or a bad side as a registration mistake', function (Closure $make, string $message) {
    Admin::topbar()->add($make());
    $user = User::factory()->withRole('super-admin')->create();

    expect(fn () => app(AdminTopbar::class)->for($user))->toThrow(LogicException::class, $message);
})->with([
    'no target' => [fn (): TopbarItem => TopbarItem::link('bare', 'Bare', 'bolt'), 'route or a url'],
    'bad side' => [fn (): TopbarItem => TopbarItem::link('odd', 'Odd', 'bolt', url: '/x', side: 'middle'), 'start or end'],
]);

it('builds the top bar without a query', function () {
    $user = User::factory()->withRole('super-admin')->create();
    app(AdminTopbar::class)->for($user);

    DB::enableQueryLog();
    app(AdminTopbar::class)->for($user);

    expect(DB::getQueryLog())->toBe([]);
});

it('returns the singletons from Admin', function () {
    expect(Admin::menu())->toBe(app(AdminMenu::class))
        ->and(Admin::topbar())->toBe(app(AdminTopbar::class))
        ->and(Admin::settings())->toBe(app(SettingsSections::class))
        ->and(Admin::assets())->toBe(app(AdminAssets::class));
});
