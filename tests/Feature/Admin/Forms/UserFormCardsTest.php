<?php

use App\Models\User;
use Hata\Admin;
use Hata\Models\Role;
use Hata\Support\Admin\Forms\FormCard;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\Forms\PersonalExtension;

beforeEach(function () {
    seedRolesAndPermissions();
    PersonalExtension::register();
    PersonalExtension::$saves = [];
    PersonalExtension::$fail = false;
    $this->actingAs(User::factory()->withRole('admin')->create());
});

function personalCard(array $overrides = []): FormCard
{
    return new FormCard(...['key' => 'personal', 'extension' => PersonalExtension::class, ...$overrides]);
}

function personalColumns(User $user): array
{
    $row = DB::table('users')->where('id', $user->id)->first();

    return [$row->birthday, $row->gender];
}

function fillNewUser(mixed $form): mixed
{
    return $form
        ->set('form.name', 'Ada Lovelace')
        ->set('form.email', 'ada@example.test')
        ->set('form.password', 'a-long-Passw0rd!')
        ->set('form.password_confirmation', 'a-long-Passw0rd!');
}

test('without a registered card the user form holds no card values and draws no card', function () {
    $html = Livewire::test('hata::admin.users.create')->assertSet('extensions', [])->html();

    expect($html)->not->toContain('data-test="form-card-');
});

test('a card renders on the create form, a side card too', function () {
    Admin::formCards('users')->add(personalCard())->add(personalCard(['key' => 'aside', 'area' => 'side']));

    $html = Livewire::test('hata::admin.users.create')->html();

    expect($html)->toContain('data-test="form-card-personal"')->toContain('data-test="form-card-aside"')
        ->toContain('wire:model="extensions.personal.birthday"');
});

test('create saves the card\'s values with the user inside the transaction', function () {
    Admin::formCards('users')->add(personalCard());
    $level = DB::transactionLevel();

    fillNewUser(Livewire::test('hata::admin.users.create'))
        ->set('extensions.personal.birthday', '1990-12-10')
        ->set('extensions.personal.gender', 'female')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'ada@example.test')->firstOrFail();

    expect(personalColumns($user))->toBe(['1990-12-10', 'female'])
        ->and(PersonalExtension::$saves)->toHaveCount(1)
        ->and(PersonalExtension::$saves[0][2])->toBeGreaterThan($level);
});

test('edit fills the card from the user and saves its new values', function () {
    Admin::formCards('users')->add(personalCard());
    $user = User::factory()->withRole('author')->create();
    DB::table('users')->where('id', $user->id)->update(['birthday' => '1980-01-02', 'gender' => 'male']);

    Livewire::test('hata::admin.users.edit', ['user' => $user->fresh()])
        ->assertSet('extensions.personal.birthday', '1980-01-02')
        ->assertSet('extensions.personal.gender', 'male')
        ->set('extensions.personal.gender', 'other')
        ->call('save')
        ->assertHasNoErrors();

    expect(personalColumns($user))->toBe(['1980-01-02', 'other']);
});

test('a card error with a valid user creates nothing', function () {
    Admin::formCards('users')->add(personalCard());

    fillNewUser(Livewire::test('hata::admin.users.create'))
        ->set('extensions.personal.birthday', 'not a date')
        ->call('save')
        ->assertHasErrors(['extensions.personal.birthday'])
        ->assertHasNoErrors('form.name')
        ->assertNoRedirect();

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse()
        ->and(PersonalExtension::$saves)->toBe([]);
});

test('a card error and a form error are shown together and nothing is saved', function () {
    Admin::formCards('users')->add(personalCard());

    Livewire::test('hata::admin.users.create')
        ->set('form.email', 'not-an-email')
        ->set('extensions.personal.birthday', 'not a date')
        ->call('save')
        ->assertHasErrors(['form.name', 'form.email', 'extensions.personal.birthday']);

    expect(PersonalExtension::$saves)->toBe([]);
});

test('the card validates by target: users leave it optional', function () {
    Admin::formCards('users')->add(personalCard());

    fillNewUser(Livewire::test('hata::admin.users.create'))->call('save')->assertHasNoErrors();

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeTrue();
});

test('an exception in the card\'s save rolls the user back', function () {
    Admin::formCards('users')->add(personalCard());
    PersonalExtension::$fail = true;

    $form = fillNewUser(Livewire::test('hata::admin.users.create'));

    expect(fn () => $form->call('save'))->toThrow(RuntimeException::class, 'The personal data could not be saved.');
    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

test('a protected action refused by UpdateUser leaves the card\'s values unsaved', function () {
    Admin::formCards('users')->add(personalCard());
    $user = User::factory()->withRole('author')->create();

    Livewire::test('hata::admin.users.edit', ['user' => $user])
        ->set('form.name', 'Renamed')
        ->set('form.roles', [(string) Role::findByName('super-admin')->id])
        ->set('extensions.personal.gender', 'forged')
        ->call('save')
        ->assertHasErrors(['form.roles'])
        ->assertNoRedirect();

    expect(personalColumns($user))->toBe([null, null])
        ->and($user->fresh()->name)->not->toBe('Renamed')
        ->and(PersonalExtension::$saves[0][1] ?? null)->toBeNull();
});
