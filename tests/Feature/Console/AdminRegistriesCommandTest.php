<?php

use Hata\Admin;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Admin\DashboardWidget;

test('it lists Hata\'s own entries', function () {
    $this->artisan('hata:admin')
        ->expectsOutputToContain('Admin menu')
        ->expectsOutputToContain('view-site')
        ->expectsOutputToContain('recent-changes')
        ->expectsOutputToContain('dashboard')
        ->expectsOutputToContain('content')
        ->expectsOutputToContain('site')
        ->assertSuccessful();
});

test('it reports unmatched modify and remove', function () {
    Admin::dashboard()->remove('nope');
    Admin::dashboard()->modify('missing', fn ($widget) => $widget);

    $this->artisan('hata:admin')
        ->expectsOutputToContain('Unmatched: modify [missing], remove [nope]')
        ->assertSuccessful();
});

test('it reports a broken entry instead of failing', function () {
    Admin::dashboard()->add(new DashboardWidget('broken', 'hata::does.not-exist'));

    $this->artisan('hata:admin')
        ->expectsOutputToContain('Left out:')
        ->expectsOutputToContain('broken')
        ->assertSuccessful();
});

test('it lists a menu item whose route needs a parameter it does not get', function () {
    Admin::menu()->add(new AdminMenuItem('needs-param', 'Needs', 'home', 'admin.content.index', null, 'content'));

    $this->artisan('hata:admin')
        ->expectsOutputToContain('Left out:')
        ->expectsOutputToContain('needs-param')
        ->assertSuccessful();
});
