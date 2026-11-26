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
    $component->set('name', 'New Name')->call('updateProfileInformation');

    expect($component->html())->not->toContain('data-test="form-card-personal"')->and(PersonalExtension::$saves)->toBe([]);
});
