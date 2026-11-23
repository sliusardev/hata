<?php

use App\Models\User;
use Hata\Models\Role;
use Hata\Support\Toast;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

test('it returns 403 without the users.create permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view'])->create());

    $this->get(route('admin.users.create'))->assertForbidden();
});

test('it is displayed with the users.create permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.create'])->create());

    $this->get(route('admin.users.create'))->assertOk();
});

test('it creates a user and redirects to the list with a message', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.users.create')
        ->assertSet('form.locale', 'en')
        ->set('form.name', 'Олена')
        ->set('form.email', 'olena@example.com')
        ->set('form.password', 'secret-password')
        ->set('form.password_confirmation', 'secret-password')
        ->set('form.locale', 'uk')
        ->set('form.roles', [(string) Role::findByName('editor')->id])
        ->set('form.email_verified', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'olena@example.com')->sole();
    expect($user->locale)->toBe('uk')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->roles->pluck('name')->all())->toBe(['editor'])
        ->and(session(Toast::SESSION_KEY)['text'])->toBe(__('users.messages.created'));
});

test('it validates the form with translated messages', function () {
    $this->actingAs(User::factory()->withRole('admin')->create(['locale' => 'uk']));
    app()->setLocale('uk');
    User::factory()->create(['email' => 'taken@example.com']);

    Livewire::test('pages::admin.users.create')
        ->set('form.name', '')
        ->set('form.email', 'taken@example.com')
        ->set('form.password', 'secret-password')
        ->set('form.password_confirmation', 'different')
        ->set('form.locale', 'xx')
        ->call('save')
        ->assertHasErrors([
            'form.name' => 'Поле ім’я обов’язкове.',
            'form.email' => 'Таке значення поля email вже використовується.',
            'form.password' => 'Підтвердження поля пароль не збігається.',
            'form.locale' => 'Вибране значення поля мова недійсне.',
        ]);

    expect(User::count())->toBe(2);
});

test('roles the actor cannot assign are disabled with an explanation', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.users.create')
        ->assertSee(__('users.form.role_unavailable'))
        ->tap(function ($component): void {
            $options = collect($component->instance()->roleOptions)->mapWithKeys(fn (array $option): array => [$option['role']->name => $option['assignable']]);

            expect($options->all())->toBe(['admin' => true, 'author' => true, 'editor' => true, 'super-admin' => false]);
        });
});

test('assigning a forbidden role shows the protection error', function () {
    $this->actingAs(User::factory()->withRole('admin')->create());

    Livewire::test('pages::admin.users.create')
        ->set('form.name', 'Someone')
        ->set('form.email', 'someone@example.com')
        ->set('form.password', 'secret-password')
        ->set('form.password_confirmation', 'secret-password')
        ->set('form.roles', [(string) Role::findByName('super-admin')->id])
        ->call('save')
        ->assertHasErrors(['form.roles' => __('users.errors.cannot_manage_super_admin')]);

    expect(User::where('email', 'someone@example.com')->exists())->toBeFalse();
});
