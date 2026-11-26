<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\Lists\TableColumn;
use Hata\Testing\AssertsListQueries;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;

uses(AssertsListQueries::class);

beforeEach(function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('admin')->create());
});

test('the helper fails on a column that queries once per row', function () {
    Admin::columns('users')->add(new TableColumn(key: 'logins', label: 'Logins', value: fn (User $user): string => (string) DB::table('users')->where('id', $user->id)->count()));

    expect(fn () => $this->assertListQueriesStable(
        fn (int $count) => User::factory()->count($count)->create(),
        fn () => Livewire::test('hata::admin.users.index')->html(),
    ))->toThrow(AssertionFailedError::class, 'a query per row');
});

test('the helper leaves a query log the test had switched on as it was', function () {
    DB::enableQueryLog();
    DB::table('users')->count();

    $this->assertListQueriesStable(
        fn (int $count) => User::factory()->count($count)->create(),
        fn () => Livewire::test('hata::admin.users.index')->html(),
    );

    expect(DB::logging())->toBeTrue()->and(DB::getQueryLog())->not->toBeEmpty();
});
