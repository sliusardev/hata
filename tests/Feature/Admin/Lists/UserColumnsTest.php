<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Testing\AssertsListQueries;
use Livewire\Livewire;

uses(AssertsListQueries::class);

beforeEach(function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create(['name' => 'Viewer', 'email' => 'viewer@example.com']));
});

/**
 * The text of the column headers, in order.
 *
 * @return list<string>
 */
function userColumnHeaders(string $html): array
{
    preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $html, $matches);

    return array_values(array_filter(array_map(fn (string $header): string => trim(preg_replace('/\s+/', ' ', strip_tags($header))), $matches[1])));
}

function loginsColumn(array $overrides = []): TableColumn
{
    return new TableColumn(...[
        'key' => 'logins',
        'label' => 'Logins',
        'value' => fn (User $user): string => 'n'.$user->roles_count,
        'with' => fn ($query) => $query->withCount('roles'),
        'sort' => fn ($query, string $direction) => $query->orderBy('roles_count', $direction),
        ...$overrides,
    ]);
}

test('the users list renders Hata columns from the registry in order', function () {
    $html = Livewire::test('hata::admin.users.index')->html();

    expect(userColumnHeaders($html))->toBe([
        __('hata::users.index.columns.user'),
        __('hata::users.index.columns.email'),
        __('hata::users.index.columns.roles'),
        __('hata::users.index.columns.locale'),
        __('hata::users.index.columns.created_at'),
        __('hata::users.index.columns.actions'),
    ]);
});

test('a site hides a Hata column and adds one with its own eager load', function () {
    Admin::columns('users')->remove('locale')->add(loginsColumn());
    User::factory()->withRole('editor')->create(['name' => 'Ed Itor']);

    $html = Livewire::test('hata::admin.users.index')->html();

    expect($html)->toContain('Logins')->toContain('n1')
        ->and($html)->not->toContain(__('hata::users.index.columns.locale'));
});

test('a sortable site column sorts the list and an unknown sort falls back', function () {
    Admin::columns('users')->add(loginsColumn());
    User::factory()->withRole('editor')->create(['name' => 'Ed Itor']);

    $component = Livewire::test('hata::admin.users.index')->set('sortBy', 'logins')->set('sortDirection', 'desc');
    expect($component->instance()->users->first()->name)->toBe('Ed Itor');

    $component->set('sortDirection', 'asc');
    expect($component->instance()->users->first()->name)->toBe('Viewer');

    $component->set('sortBy', 'nope')->set('sortDirection', 'desc');
    expect($component->instance()->users->pluck('id')->all())->toBe(User::query()->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all());
});

test('a column hidden by permission is neither shown nor loaded nor sortable', function () {
    $loaded = false;
    Admin::columns('users')->add(loginsColumn([
        'permission' => 'roles.edit',
        'with' => function ($query) use (&$loaded) {
            $loaded = true;
            $query->withCount('roles');
        },
    ]));

    $component = Livewire::test('hata::admin.users.index')->call('sort', 'logins');

    expect($component->html())->not->toContain('Logins')
        ->and($loaded)->toBeFalse()
        ->and($component->get('sortBy'))->toBe('created_at');
});

test('the users list does not grow in queries with rows', function () {
    Admin::columns('users')->add(loginsColumn());

    $this->assertListQueriesStable(
        fn (int $count) => User::factory()->count($count)->withRole('editor')->create(),
        fn () => Livewire::test('hata::admin.users.index'),
    );
});
