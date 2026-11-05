<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

test('guests are redirected to the login page', function () {
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
});

test('it returns 403 without the roles.view permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.roles.index'))->assertForbidden();
});

test('it lists roles with translated names, user and permission counts', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());
    User::factory()->count(2)->withRole('editor')->create();
    Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);

    Livewire::test('pages::admin.roles.index')
        ->assertSee(__('roles.names.editor'))
        ->assertSee('Moderator')
        ->assertSee(__('roles.all_permissions'))
        ->tap(function ($component): void {
            $roles = $component->instance()->roles->keyBy('name');

            expect($roles['editor']->users_count)->toBe(2)
                ->and($roles['admin']->permissions_count)->toBe(9);
        });
});

test('it counts users and permissions without a query per role', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());
    foreach (range(1, 5) as $index) {
        Role::create(['name' => "custom-{$index}", 'guard_name' => 'web']);
    }

    DB::enableQueryLog();
    Livewire::test('pages::admin.roles.index');
    $countQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'role_has_permissions'));

    expect($countQueries->count())->toBeLessThanOrEqual(2);
});

test('the navigation shows roles only with the roles.view permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());
    $this->get(route('admin.dashboard'))->assertSee(route('admin.roles.index'));

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $this->get(route('admin.dashboard'))->assertDontSee(route('admin.roles.index'));
});

test('the role name links to the role page', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());
    $editor = Role::findByName('editor');

    Livewire::test('pages::admin.roles.index')
        ->assertSeeHtml('data-test="edit-role-link-'.$editor->id.'"')
        ->assertSeeHtml('href="'.route('admin.roles.edit', $editor).'"');
});

test('delete buttons are shown only for custom roles', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $custom = Role::create(['name' => 'moderator', 'guard_name' => 'web']);

    Livewire::test('pages::admin.roles.index')
        ->assertSeeHtml('data-test="delete-role-'.$custom->id.'"')
        ->assertDontSeeHtml('data-test="delete-role-'.Role::findByName('editor')->id.'"')
        ->assertDontSeeHtml('data-test="delete-role-'.Role::findByName('super-admin')->id.'"');
});

test('it deletes an unused custom role from the list', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $custom = Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);

    Livewire::test('pages::admin.roles.index')
        ->call('confirmDelete', $custom->id)
        ->assertSee(__('roles.delete.confirm', ['name' => 'Moderator']))
        ->call('deleteRole')
        ->assertDispatched('toast-show');

    $this->assertModelMissing($custom);
});

test('the delete dialog explains how many users have the role', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $custom = Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);
    User::factory()->count(3)->withRole('moderator')->create();

    Livewire::test('pages::admin.roles.index')
        ->call('confirmDelete', $custom->id)
        ->assertSee(__('roles.delete.in_use', ['name' => 'Moderator', 'users' => trans_choice('users.count', 3)]))
        ->assertDontSeeHtml('data-test="confirm-modal-button"');
});
