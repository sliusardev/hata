<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(fn () => seedRolesAndPermissions());

function usersViewer(): User
{
    return User::factory()->withPermissions(['admin.access', 'users.view'])->create(['name' => 'Viewer', 'email' => 'viewer@example.com']);
}

test('guests are redirected to the login page', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});

test('it returns 403 without the users.view permission', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.users.index'))->assertForbidden();
});

test('it lists users with their translated roles', function () {
    $this->actingAs(usersViewer());
    User::factory()->withRole('editor')->create(['name' => 'Ed Itor']);

    $this->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('Ed Itor')
        ->assertSee(__('roles.names.editor'));
});

test('the navigation shows users only with the users.view permission', function () {
    $this->actingAs(usersViewer());
    $this->get(route('admin.dashboard'))->assertSee(route('admin.users.index'));

    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    $this->get(route('admin.dashboard'))->assertDontSee(route('admin.users.index'));
});

test('search ignores the case of Cyrillic names', function (string $term) {
    $this->actingAs(usersViewer());
    User::factory()->create(['name' => 'Олена Петренко']);
    User::factory()->create(['name' => 'Іван Сірко']);

    Livewire::test('pages::admin.users.index')
        ->set('search', $term)
        ->assertSee('Олена Петренко')
        ->assertDontSee('Іван Сірко');
})->with(['олена', 'ОЛЕНА', 'оЛеНа ПЕТ']);

test('search matches the email address', function () {
    $this->actingAs(usersViewer());
    User::factory()->create(['name' => 'Mail Match', 'email' => 'unique.person@example.com']);
    User::factory()->create(['name' => 'Someone Else']);

    Livewire::test('pages::admin.users.index')
        ->set('search', 'UNIQUE.PERSON')
        ->assertSee('Mail Match')
        ->assertDontSee('Someone Else');
});

test('search finds users after their name changes', function () {
    $this->actingAs(usersViewer());
    $user = User::factory()->create(['name' => 'Old Name']);
    $user->update(['name' => 'Тарас Шевченко']);

    Livewire::test('pages::admin.users.index')
        ->set('search', 'тарас')
        ->assertSee('Тарас Шевченко');
});

test('it filters users by role', function () {
    $this->actingAs(usersViewer());
    User::factory()->withRole('editor')->create(['name' => 'Editor Person']);
    User::factory()->withRole('author')->create(['name' => 'Author Person']);

    Livewire::test('pages::admin.users.index')
        ->set('role', (string) Role::findByName('editor')->id)
        ->assertSee('Editor Person')
        ->assertDontSee('Author Person');
});

test('it sorts users by name in both directions', function () {
    $this->actingAs(usersViewer());
    User::factory()->create(['name' => 'Zed Last']);
    User::factory()->create(['name' => 'Abe First']);

    Livewire::test('pages::admin.users.index')
        ->call('sort', 'name')
        ->assertSeeInOrder(['Abe First', 'Viewer', 'Zed Last'])
        ->call('sort', 'name')
        ->assertSeeInOrder(['Zed Last', 'Viewer', 'Abe First']);
});

test('it ignores unknown sort columns', function () {
    $this->actingAs(usersViewer());

    Livewire::test('pages::admin.users.index')
        ->call('sort', 'password')
        ->assertSet('sortBy', 'created_at');
});

test('search, filter, sorting and page are read from the URL', function () {
    $this->actingAs(usersViewer());
    User::factory()->withRole('editor')->create(['name' => 'Олена Редактор']);

    Livewire::withQueryParams(['search' => 'олена', 'role' => (string) Role::findByName('editor')->id, 'sortBy' => 'name', 'sortDirection' => 'asc'])
        ->test('pages::admin.users.index')
        ->assertSet('search', 'олена')
        ->assertSet('sortBy', 'name')
        ->assertSee('Олена Редактор');
});

test('it paginates by 25 users per page', function () {
    $this->actingAs(usersViewer());
    User::factory()->count(30)->create();

    $component = Livewire::test('pages::admin.users.index');

    expect($component->instance()->users->perPage())->toBe(25)
        ->and($component->instance()->users->count())->toBe(25)
        ->and($component->instance()->users->total())->toBe(31);
});

test('it shows a no results state when nothing matches', function () {
    $this->actingAs(usersViewer());

    Livewire::test('pages::admin.users.index')
        ->set('search', 'nobody-matches-this')
        ->assertSee(__('users.index.no_results.title'))
        ->assertDontSee(__('users.index.empty.title'));
});

test('it loads roles without a query per user', function () {
    $this->actingAs(usersViewer());
    User::factory()->count(10)->withRole('editor')->create();

    DB::enableQueryLog();
    Livewire::test('pages::admin.users.index');
    $roleQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'model_has_roles'));

    expect($roleQueries->count())->toBeLessThanOrEqual(3);
});

describe('deleting from the list', function () {
    test('users with users.delete can delete another user', function () {
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'users.view', 'users.delete'])->create());
        $user = User::factory()->create(['name' => 'To Delete']);

        Livewire::test('pages::admin.users.index')
            ->call('confirmDelete', $user->id)
            ->assertSet('showDeleteModal', true)
            ->assertSee(__('users.delete.confirm', ['name' => 'To Delete']))
            ->call('deleteUser')
            ->assertSet('showDeleteModal', false)
            ->assertDontSee('To Delete');

        $this->assertModelMissing($user);
    });

    test('users without users.delete cannot delete', function () {
        $this->actingAs(usersViewer());
        $user = User::factory()->create();

        Livewire::test('pages::admin.users.index')
            ->call('confirmDelete', $user->id)
            ->assertForbidden();

        $this->assertModelExists($user);
    });

    test('the delete button is hidden for the current user', function () {
        $actor = User::factory()->withPermissions(['admin.access', 'users.view', 'users.delete'])->create();
        $this->actingAs($actor);
        $other = User::factory()->create();

        Livewire::test('pages::admin.users.index')
            ->assertSeeHtml('data-test="delete-user-'.$other->id.'"')
            ->assertDontSeeHtml('data-test="delete-user-'.$actor->id.'"');
    });

    test('a protected deletion is rejected with a message', function () {
        $actor = User::factory()->withRole('admin')->create();
        $this->actingAs($actor);

        Livewire::test('pages::admin.users.index')
            ->call('confirmDelete', $actor->id)
            ->call('deleteUser')
            ->assertDispatched('toast-show');

        $this->assertModelExists($actor);
    });
});
