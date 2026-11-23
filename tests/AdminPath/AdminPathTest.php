<?php

use App\Models\User;
use Hata\Models\Entry;
use Hata\Support\Localization\ReservedSlugs;
use Hata\Support\Settings\SiteSettings;

beforeEach(function () {
    seedRolesAndPermissions();
    app(SiteSettings::class)->put([
        'content_locales' => [['code' => 'en', 'active' => true]],
        'content_default_locale' => 'en',
    ]);
});

it('serves the admin under a configured path', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());

    $this->get('/cp-7f3k')->assertOk();
    $this->get('/admin')->assertNotFound();
    $this->get('/cp-7f3k/users')->assertSuccessful();

    expect(route('admin.dashboard'))->toEndWith('/cp-7f3k')
        ->and(route('admin.media.index'))->toEndWith('/cp-7f3k/media');
});

it('frees the default admin slug for content', function () {
    Entry::factory()->page()->published()->create(['title' => 'Admin of the house', 'slug' => 'admin', 'path' => 'admin']);

    $this->get('/admin')->assertOk()->assertSee('Admin of the house');
});

it('reserves the configured path for content', function () {
    $reserved = app(ReservedSlugs::class);

    expect($reserved->contains('cp-7f3k'))->toBeTrue()
        ->and($reserved->contains('admin'))->toBeFalse();
});
