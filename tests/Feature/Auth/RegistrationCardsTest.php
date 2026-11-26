<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\Forms\FormCard;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\Forms\PersonalExtension;

beforeEach(function () {
    seedRolesAndPermissions();
    config(['hata.admin.registration_enabled' => true, 'hata.admin.default_role' => 'editor']);
    PersonalExtension::register();
    PersonalExtension::$saves = [];
    PersonalExtension::$fail = false;
});

function registrationInput(array $extra = []): array
{
    return [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'a-long-Passw0rd!',
        'password_confirmation' => 'a-long-Passw0rd!',
        ...$extra,
    ];
}

function registrationCard(string|Closure|null $permission = null): FormCard
{
    return new FormCard(key: 'personal', extension: PersonalExtension::class, permission: $permission);
}

test('without a registered card the form has no card', function () {
    expect($this->get(route('register'))->getContent())->not->toContain('form-card-');
});

test('the card is on the registration form with plain field names', function () {
    Admin::formCards('register')->add(registrationCard());

    $this->get(route('register'))->assertOk()
        ->assertSee('data-test="form-card-personal"', false)
        ->assertSee('name="extensions[personal][birthday]"', false);
});

test('registration saves the card with the user and the default role', function () {
    Admin::formCards('register')->add(registrationCard());
    $level = DB::transactionLevel();

    $this->post(route('register.store'), registrationInput(['extensions' => ['personal' => ['birthday' => '1990-12-10', 'gender' => 'f']]]))
        ->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'ada@example.test')->firstOrFail();
    $row = DB::table('users')->where('id', $user->id)->first();

    expect($row->birthday)->toStartWith('1990-12-10')->and($row->gender)->toBe('f')
        ->and($user->hasRole('editor'))->toBeTrue()
        ->and(PersonalExtension::$saves[0][2])->toBe($level + 1);
});

test('a missing required card field creates no user and brings every error and the old input back', function () {
    Admin::formCards('register')->add(registrationCard());

    $this->from(route('register'))
        ->post(route('register.store'), registrationInput(['email' => 'not-an-email', 'extensions' => ['personal' => ['birthday' => '', 'gender' => 'f']]]))
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors(['email', 'extensions.personal.birthday'])
        ->assertSessionHasInput('name', 'Ada Lovelace')
        ->assertSessionHasInput('extensions.personal.gender', 'f');

    expect(User::query()->where('name', 'Ada Lovelace')->exists())->toBeFalse();
});

test('the error shows under the card field and the old input refills it', function () {
    Admin::formCards('register')->add(registrationCard());

    $html = $this->followingRedirects()->from(route('register'))
        ->post(route('register.store'), registrationInput(['extensions' => ['personal' => ['birthday' => '', 'gender' => 'f']]]))
        ->getContent();

    expect($html)->toContain('value="f"')->toContain('birthday field is required');
});

test('a card that fails while saving leaves no user', function () {
    Admin::formCards('register')->add(registrationCard());
    PersonalExtension::$fail = true;

    $this->withoutExceptionHandling();

    expect(fn () => $this->post(route('register.store'), registrationInput(['extensions' => ['personal' => ['birthday' => '1990-12-10', 'gender' => 'f']]])))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeFalse();
});

test('a card behind an ability is not shown to a guest and its values are ignored', function () {
    Admin::formCards('register')->add(registrationCard('users.view'));

    $this->get(route('register'))->assertDontSee('form-card-personal');

    $this->post(route('register.store'), registrationInput(['extensions' => ['personal' => ['birthday' => 'garbage', 'gender' => 'f']]]))
        ->assertSessionHasNoErrors();

    expect(PersonalExtension::$saves)->toBe([])->and(User::query()->where('email', 'ada@example.test')->exists())->toBeTrue();
});

test('a closure permission receives null for a guest', function () {
    Admin::formCards('register')->add(registrationCard(fn ($actor) => $actor === null));

    $this->get(route('register'))->assertSee('form-card-personal', false);
});
