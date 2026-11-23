<?php

use App\Models\User;
use Hata\Models\Role;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

test('it returns 403 without the users.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());
    $user = User::factory()->create();

    $this->get(route('admin.users.edit', $user))->assertForbidden();
});

test('it is displayed with the users.edit permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.edit'])->create());
    $user = User::factory()->create();

    $this->get(route('admin.users.edit', $user))->assertOk()->assertSee($user->email);
});

test('it returns 403 when a non super admin opens a super admin', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $superAdmin = User::factory()->withRole('super-admin')->create();

    $this->get(route('admin.users.edit', $superAdmin))->assertForbidden();
});

test('it updates a user without changing the password', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $user = User::factory()->withRole('author')->create(['password' => 'old-password']);

    Livewire::test('hata::admin.users.edit', ['user' => $user])
        ->assertSet('form.name', $user->name)
        ->set('form.name', 'Updated Name')
        ->set('form.roles', [(string) Role::findByName('editor')->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();
    expect($user->name)->toBe('Updated Name')
        ->and(Hash::check('old-password', $user->password))->toBeTrue()
        ->and($user->roles->pluck('name')->all())->toBe(['editor']);
});

test('it updates the password when one is entered', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $user = User::factory()->create();

    Livewire::test('hata::admin.users.edit', ['user' => $user])
        ->set('form.password', 'brand-new-password')
        ->set('form.password_confirmation', 'brand-new-password')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('brand-new-password', $user->fresh()->password))->toBeTrue();
});

test('the roles block is locked on the own account and roles stay unchanged', function () {
    $actor = User::factory()->withRole('admin')->create();
    $this->actingAs($actor);

    Livewire::test('hata::admin.users.edit', ['user' => $actor])
        ->assertSee(__('users.form.own_roles_locked'))
        ->set('form.roles', [(string) Role::findByName('author')->id])
        ->set('form.name', 'Self Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($actor->fresh()->name)->toBe('Self Renamed')
        ->and($actor->fresh()->roles->pluck('name')->all())->toBe(['admin']);
});

test('it deletes the user from the edit page', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());
    $user = User::factory()->create();

    Livewire::test('hata::admin.users.edit', ['user' => $user])
        ->call('deleteUser')
        ->assertRedirect(route('admin.users.index'));

    $this->assertModelMissing($user);
});

test('the delete button is hidden on the own account', function () {
    $actor = User::factory()->withRole('admin')->create();
    $this->actingAs($actor);

    Livewire::test('hata::admin.users.edit', ['user' => $actor])
        ->assertDontSeeHtml('data-test="delete-user-button"');
});
