<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\Forms\FormCard;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fixtures\Forms\PersonalExtension;

beforeEach(function () {
    PersonalExtension::register();
    PersonalExtension::$saves = [];
    PersonalExtension::$fail = false;
    PersonalExtension::$refuse = false;
    $this->user = User::factory()->create(['name' => 'Old Name']);
    $this->actingAs($this->user);
});

function profileCard(): FormCard
{
    return new FormCard(key: 'personal', extension: PersonalExtension::class);
}

test('without a registered card the profile page draws no card', function () {
    $html = Livewire::test('hata::settings.profile')->assertSet('extensions', [])->html();

    expect($html)->not->toContain('data-test="form-card-');
});

test('the profile page shows the card with the user\'s values', function () {
    DB::table('users')->where('id', $this->user->id)->update(['birthday' => '1990-12-10', 'gender' => 'f']);
    $this->user->refresh();
    Admin::formCards('profile')->add(profileCard());

    $component = Livewire::test('hata::settings.profile')->assertSet('extensions.personal.birthday', '1990-12-10');

    expect($component->html())->toContain('data-test="form-card-personal"')->toContain('wire:model="extensions.personal.birthday"');
});

test('saving the profile saves the card with the user inside one transaction', function () {
    Admin::formCards('profile')->add(profileCard());
    $level = DB::transactionLevel();

    Livewire::test('hata::settings.profile')
        ->set('name', 'New Name')
        ->set('extensions.personal.birthday', '1990-12-10')
        ->set('extensions.personal.gender', 'f')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $row = DB::table('users')->where('id', $this->user->id)->first();

    expect($row->name)->toBe('New Name')->and($row->birthday)->toStartWith('1990-12-10')->and($row->gender)->toBe('f')
        ->and(PersonalExtension::$saves[0][2])->toBe($level + 1);
});

test('a card error saves nothing', function () {
    Admin::formCards('profile')->add(profileCard());

    Livewire::test('hata::settings.profile')
        ->set('name', 'New Name')
        ->set('extensions.personal.birthday', 'not a date')
        ->call('updateProfileInformation')
        ->assertHasErrors(['extensions.personal.birthday']);

    expect($this->user->fresh()->name)->toBe('Old Name')->and(PersonalExtension::$saves)->toBe([]);
});

test('a card that fails while saving rolls the user back', function () {
    Admin::formCards('profile')->add(profileCard());
    PersonalExtension::$fail = true;

    expect(fn () => Livewire::test('hata::settings.profile')
        ->set('name', 'New Name')
        ->set('extensions.personal.birthday', '1990-12-10')
        ->call('updateProfileInformation'))->toThrow(RuntimeException::class);

    expect($this->user->fresh()->name)->toBe('Old Name');
});

test('a card the user may not see is not drawn or saved', function () {
    Admin::formCards('profile')->add(new FormCard(key: 'personal', extension: PersonalExtension::class, permission: 'users.view'));

    $component = Livewire::test('hata::settings.profile')->assertSet('extensions', []);
    $component->set('name', 'New Name')
        ->set('extensions.personal.birthday', 'garbage')
        ->set('extensions.personal.gender', 'f')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($component->html())->not->toContain('data-test="form-card-personal"')->and(PersonalExtension::$saves)->toBe([])
        ->and(DB::table('users')->where('id', $this->user->id)->value('gender'))->toBeNull();
});

test('a side card is drawn on the profile page after the main ones', function () {
    Admin::formCards('profile')
        ->add(new FormCard(key: 'aside', extension: PersonalExtension::class, area: 'side'))
        ->add(profileCard());

    $html = Livewire::test('hata::settings.profile')->html();

    expect($html)->toContain('data-test="form-card-aside"')
        ->and(strpos($html, 'data-test="form-card-personal"'))->toBeLessThan(strpos($html, 'data-test="form-card-aside"'));
});

test('a ValidationException from the card\'s save rolls back and leaves the signed-in user as stored', function () {
    Admin::formCards('profile')->add(profileCard());
    PersonalExtension::$refuse = true;

    Livewire::test('hata::settings.profile')
        ->set('name', 'New Name')
        ->set('extensions.personal.birthday', '1990-12-10')
        ->call('updateProfileInformation')
        ->assertHasErrors(['extensions.personal.birthday']);

    expect($this->user->fresh()->name)->toBe('Old Name')->and(auth()->user()->name)->toBe('Old Name');
});

test('a form card permission closure gets the form context', function () {
    Admin::formCards('profile')->add(new FormCard(key: 'personal', extension: PersonalExtension::class, permission: fn ($actor, $context) => $context->target === 'profile' && ! $context->isCreate()));

    expect(Livewire::test('hata::settings.profile')->html())->toContain('data-test="form-card-personal"');
});
