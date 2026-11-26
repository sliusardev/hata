<?php

use Hata\Admin;
use Hata\Support\Admin\AdminMenuItem;
use Hata\Support\Admin\DashboardWidget;
use Hata\Support\Admin\Lists\RowAction;
use Hata\Support\Admin\Lists\TableColumn;

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

test('it lists the columns of the users list and of a content type a site changed', function () {
    Admin::columns('content.posts')->add(new TableColumn('views', 'Views', fn () => '1', order: 45));
    Admin::columns('content.posts')->remove('author');
    Admin::columns('content.posts')->modify('nope', fn ($column) => $column);
    Admin::rowActions('users')->add(RowAction::link('profile', 'Profile', 'user', fn ($user) => '/u/'.$user->id));

    $this->artisan('hata:admin')
        ->expectsOutputToContain('List columns [users]')
        ->expectsOutputToContain('created_at')
        ->expectsOutputToContain('List columns [content.posts]')
        ->expectsOutputToContain('views')
        ->expectsOutputToContain('Unmatched: modify [nope]')
        ->expectsOutputToContain('Row actions [users]')
        ->assertSuccessful();
});
