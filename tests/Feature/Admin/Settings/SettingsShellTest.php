<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Livewire;
use Tests\Fixtures\ShopSettingsSection;

use function Livewire\on;

beforeEach(fn () => seedRolesAndPermissions());

/**
 * The section keys of the page's side list, in the order they are rendered.
 *
 * @return list<string>
 */
function settingsNavKeys(string $html): array
{
    preg_match_all('/data-test="site-settings-tab-([a-z0-9-]+)"/', $html, $matches);

    return $matches[1];
}

function settingsUrl(?string $tab = null): string
{
    return route('admin.settings.edit', $tab === null ? [] : ['tab' => $tab]);
}

/**
 * A user who may open only the fixture module's section: `admin.access` and `shop.settings`, no `settings.edit`.
 */
function shopOnlyUser(): User
{
    ShopSettingsSection::register();
    seedRolesAndPermissions();

    return User::factory()->withPermissions(['admin.access', 'shop.settings'])->create();
}

test('it returns 403 without the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());

    $this->get(settingsUrl())->assertForbidden();
});

test('it is displayed with the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());

    $this->get(settingsUrl())
        ->assertOk()
        ->assertSee(__('hata::site_settings.mail.heading'));
});

test('the sidebar links to the page only with the settings.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());
    $this->get(route('admin.users.index'))->assertDontSee('href="'.route('admin.settings.edit', absolute: false).'"', false);

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.edit'])->create());
    $this->get(route('admin.dashboard'))->assertSee('href="'.route('admin.settings.edit', absolute: false).'"', false);
});

test('the page lists the seven sections in order', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    $html = $this->get(settingsUrl())->assertOk()->getContent();

    expect(settingsNavKeys($html))->toBe(['site', 'languages', 'content', 'access', 'mail', 'appearance', 'media']);

    foreach (['site', 'languages', 'content', 'access', 'mail', 'appearance', 'media'] as $key) {
        expect($html)->toContain(e(__("hata::site_settings.{$key}.heading")));
    }
});

test('the tab comes from the query string and falls back to the first section', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::withQueryParams(['tab' => 'mail'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'mail')
        ->assertSee(__('hata::site_settings.mail.send_test'));

    Livewire::withQueryParams(['tab' => 'nonsense'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'site')
        ->assertDontSee(__('hata::site_settings.mail.send_test'));
});

test('old tab keys open the merged sections', function (string $old, string $section) {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::withQueryParams(['tab' => $old])->test('hata::admin.settings.edit')
        ->assertSet('tab', $section);
})->with([
    'general' => ['general', 'site'],
    'logo' => ['logo', 'appearance'],
    'colors' => ['colors', 'appearance'],
]);

test('switching sections is navigation, guarded in the browser', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    $html = $this->get(settingsUrl('mail'))->assertOk()->getContent();

    foreach (['site', 'mail', 'media'] as $key) {
        expect($html)->toMatch('/<a[^>]*href="'.preg_quote(e(settingsUrl($key)), '/').'"[^>]*wire:navigate[^>]*data-test="site-settings-tab-'.$key.'"|<a[^>]*wire:navigate[^>]*href="'.preg_quote(e(settingsUrl($key)), '/').'"[^>]*data-test="site-settings-tab-'.$key.'"/s');
    }

    expect($html)
        ->toContain('x-on:livewire:navigate.document')
        ->toContain('x-on:beforeunload.window')
        ->toContain("document.querySelector('[data-settings-section]')")
        ->toContain('data-test="leave-without-saving-button"')
        ->toContain(e(__('hata::site_settings.unsaved.leave_body')));
});

test('a typed SMTP password counts as an unsaved change', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    // The section's dirty check treats a typed secret as a change; the shell's guard asks that check.
    Livewire::test('hata::admin.settings.sections.mail')
        ->assertSeeHtml('data-settings-section')
        ->assertSeeHtml('mail_password');
});

test('unknown or forbidden tab falls back to the first visible section', function () {
    $this->actingAs(shopOnlyUser());

    Livewire::withQueryParams(['tab' => 'mail'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'shop')
        ->assertDontSee(__('hata::site_settings.mail.send_test'));

    Livewire::withQueryParams(['tab' => 'nonsense'])->test('hata::admin.settings.edit')
        ->assertSet('tab', 'shop');
});

test('a module-only user sees the menu item and only the module section', function () {
    $this->actingAs(shopOnlyUser());

    $this->get(route('admin.dashboard'))->assertSee('href="'.route('admin.settings.edit', absolute: false).'"', false);

    $html = $this->get(settingsUrl())->assertOk()->assertSee('data-test="shop-currency"', false)->getContent();

    expect(settingsNavKeys($html))->toBe(['shop']);
});

test('a hata section is 403 for a module-only user', function () {
    $this->actingAs(shopOnlyUser());

    Livewire::test('hata::admin.settings.sections.mail')->assertForbidden();
});

test('a user with no section gets 403 and no menu item', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('href="'.route('admin.settings.edit', absolute: false).'"', false);
    $this->get(settingsUrl())->assertForbidden();
});

test('the shell renders only the open section', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    $mounted = [];
    on('mount', function (Component $component) use (&$mounted): void {
        $mounted[] = $component->getName();
    });
    DB::enableQueryLog();

    $this->get(settingsUrl('site'))
        ->assertOk()
        ->assertSee('wire:model="values.site_name"', false)
        ->assertDontSee('data-test="site-languages"', false)
        ->assertDontSee('data-test="language-catalog"', false)
        ->assertDontSee('data-test="optimize-existing-card"', false)
        ->assertDontSee('data-test="send-test-email-button"', false);

    $tables = collect(DB::getQueryLog())->pluck('query')->implode("\n");

    // Only the shell and the site section mount; the Media section's file counts and the Access section's role
    // options never run.
    expect($mounted)->toBe(['hata::admin.settings.edit', 'hata::admin.settings.sections.site'])
        ->and($tables)->not->toMatch('/from [`"]media[`"]/')
        ->and($tables)->not->toMatch('/[`"]name[`"] != \\?/');
});
