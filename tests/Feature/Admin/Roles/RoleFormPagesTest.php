<?php

use App\Models\User;
use Hata\Models\Role;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

describe('create', function () {
    test('it returns 403 without the roles.create permission', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());

        $this->get(route('admin.roles.create'))->assertForbidden();
    });

    test('it is displayed with the roles.create permission', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.create'])->create());

        $this->get(route('admin.roles.create'))->assertOk()->assertSee(__('permissions.groups.users'));
    });

    test('it generates the technical name from the label until it is edited', function () {
        $this->actingAs(User::factory()->withRole('admin')->create());

        Livewire::test('hata::admin.roles.create')
            ->set('form.label', 'Головний редактор')
            ->assertSet('form.name', 'golovnii-redaktor')
            ->set('form.name', 'chief')
            ->set('form.label', 'Chief editor')
            ->assertSet('form.name', 'chief');
    });

    test('it creates a role and redirects to the list with a message', function () {
        $this->actingAs(User::factory()->withRole('admin')->create());

        Livewire::test('hata::admin.roles.create')
            ->set('form.label', 'Moderator')
            ->set('form.permissions', ['admin.access', 'users.view'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.roles.index'));

        $role = Role::findByName('moderator');
        expect($role->label)->toBe('Moderator')
            ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['admin.access', 'users.view'])
            ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('roles.messages.created'));
    });

    test('it validates the label and technical name', function (string $name) {
        $this->actingAs(User::factory()->withRole('admin')->create());

        Livewire::test('hata::admin.roles.create')
            ->set('form.label', 'Something')
            ->set('form.name', $name)
            ->call('save')
            ->assertHasErrors('form.name');
    })->with(['Upper-Case', 'with space', 'editor', 'trailing-', 'кирилиця']);

    test('it requires a label', function () {
        $this->actingAs(User::factory()->withRole('admin')->create());

        Livewire::test('hata::admin.roles.create')
            ->set('form.name', 'valid-name')
            ->call('save')
            ->assertHasErrors(['form.label' => 'required']);
    });

    test('select all only checks permissions the actor may grant', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.create', 'users.view'])->create());

        Livewire::test('hata::admin.roles.create')
            ->call('selectGroup', 'users')
            ->assertSet('form.permissions', ['users.view'])
            ->call('clearGroup', 'users')
            ->assertSet('form.permissions', [])
            ->assertSee(__('roles.form.permission_unavailable'));
    });
});

describe('edit', function () {
    test('it returns 403 without roles.view or roles.edit', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

        $this->get(route('admin.roles.edit', Role::findByName('editor')))->assertForbidden();
    });

    test('it opens in view mode with roles.view only', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'roles.view'])->create());

        Livewire::test('hata::admin.roles.edit', ['role' => Role::findByName('editor')])
            ->assertSee(__('roles.edit.read_only'))
            ->assertDontSeeHtml('data-test="save-role-button"')
            ->call('save')
            ->assertForbidden();
    });

    test('it updates the permissions of a system role', function () {
        $this->actingAs(User::factory()->withRole('admin')->create());
        $editor = Role::findByName('editor');

        Livewire::test('hata::admin.roles.edit', ['role' => $editor])
            ->call('selectGroup', 'users')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.roles.index'));

        expect($editor->fresh()->permissions->pluck('name')->sort()->values()->all())
            ->toBe(['admin.access', 'media.delete', 'media.edit', 'media.upload', 'media.view', 'pages.create', 'pages.delete', 'pages.edit', 'pages.view', 'users.create', 'users.delete', 'users.edit', 'users.view']);
    });

    test('the super-admin role is shown as having every permission and cannot be saved', function () {
        $this->actingAs(User::factory()->withRole('super-admin')->create());

        Livewire::test('hata::admin.roles.edit', ['role' => Role::findByName('super-admin')])
            ->assertSee(__('roles.form.super_admin_heading'))
            ->assertDontSeeHtml('data-test="save-role-button"')
            ->call('save')
            ->assertHasErrors(['form.permissions' => __('roles.errors.super_admin_locked')]);
    });

    test('it deletes an unused custom role from the edit page', function () {
        $this->actingAs(User::factory()->withRole('admin')->create());
        $role = Role::create(['name' => 'moderator', 'label' => 'Moderator', 'guard_name' => 'web']);

        Livewire::test('hata::admin.roles.edit', ['role' => $role])
            ->call('deleteRole')
            ->assertRedirect(route('admin.roles.index'));

        $this->assertModelMissing($role);
    });
});
