<?php

use App\Models\User;
use Hata\Support\Admin\SettingsSection;
use Hata\Support\Admin\SettingsSections;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\Livewire;

class SettingsSectionsFixture extends Component
{
    public function render(): string
    {
        return '<div></div>';
    }
}

beforeEach(function () {
    seedRolesAndPermissions();

    // These tests exercise the registry on its own, without Hata's registered sections.
    $this->app->instance(SettingsSections::class, new SettingsSections);
    Livewire::component('settings-sections-fixture', SettingsSectionsFixture::class);
});

function section(string $key, int $order = 100, string|Closure|null $permission = null, string $component = 'settings-sections-fixture'): SettingsSection
{
    return new SettingsSection($key, 'Label '.$key, 'star', $component, $permission, $order);
}

function keysFor(User $user): array
{
    return array_map(fn (SettingsSection $s): string => $s->key, app(SettingsSections::class)->for($user));
}

it('is a singleton', function () {
    expect(app(SettingsSections::class))->toBe(app(SettingsSections::class));
});

it('orders sections by order, then by insertion', function () {
    app(SettingsSections::class)
        ->add(section('c', 20))
        ->add(section('a', 10))
        ->add(section('b', 20));

    expect(keysFor(User::factory()->create()))->toBe(['a', 'c', 'b']);
});

it('replaces a section with the same key and removes one', function () {
    $sections = app(SettingsSections::class)
        ->add(section('a', 10))
        ->add(section('b', 20))
        ->add(new SettingsSection('a', 'Renamed', 'star', 'settings-sections-fixture', null, 30));

    expect($sections->find('a')->label)->toBe('Renamed')
        ->and(keysFor(User::factory()->create()))->toBe(['b', 'a']);

    $sections->remove('a');

    expect($sections->find('a'))->toBeNull()
        ->and(keysFor(User::factory()->create()))->toBe(['b']);
});

it('hides a section whose ability the user lacks, and one whose closure says no', function () {
    app(SettingsSections::class)
        ->add(section('gated', 10, 'settings.edit'))
        ->add(section('open', 20))
        ->add(section('closure-no', 30, fn ($user): bool => false))
        ->add(section('closure-yes', 40, fn ($user): bool => true));

    $without = User::factory()->withPermissions(['admin.access'])->create();
    $with = User::factory()->withPermissions(['admin.access', 'settings.edit'])->create();

    expect(keysFor($without))->toBe(['open', 'closure-yes'])
        ->and(keysFor($with))->toBe(['gated', 'open', 'closure-yes']);
});

it('says whether a user sees any section', function () {
    app(SettingsSections::class)->add(section('gated', 10, 'settings.edit'));

    expect(app(SettingsSections::class)->visibleTo(User::factory()->withPermissions(['admin.access'])->create()))->toBeFalse()
        ->and(app(SettingsSections::class)->visibleTo(User::factory()->withPermissions(['settings.edit'])->create()))->toBeTrue();
});

it('answers visibleTo() from permissions alone, never resolving a component', function () {
    app(SettingsSections::class)->add(section('ghost', 10, null, 'no-such-component'));
    $user = User::factory()->create();

    expect(app(SettingsSections::class)->visibleTo($user))->toBeTrue()
        ->and(fn () => app(SettingsSections::class)->for($user))->toThrow(LogicException::class, 'ghost');
});

it('checks one section with allows()', function () {
    $gated = section('gated', 10, 'settings.edit');

    expect(app(SettingsSections::class)->allows(User::factory()->create(), $gated))->toBeFalse()
        ->and(app(SettingsSections::class)->allows(User::factory()->withPermissions(['settings.edit'])->create(), $gated))->toBeTrue();
});

it('accepts a component class', function () {
    app(SettingsSections::class)->add(section('by-class', 10, null, SettingsSectionsFixture::class));

    expect(keysFor(User::factory()->create()))->toBe(['by-class']);
});

it('throws for a bad key or an unknown component, naming the section', function (string $key, string $component) {
    app(SettingsSections::class)->add(section($key, 10, null, $component));
    $user = User::factory()->create();

    expect(fn () => app(SettingsSections::class)->for($user))->toThrow(LogicException::class, $key);
})->with([
    'bad key' => ['Bad_Key', 'settings-sections-fixture'],
    'unknown component' => ['ghost', 'no-such-component'],
]);

it('does not check a component until the page asks', function () {
    app(SettingsSections::class)->add(section('ghost', 10, null, 'no-such-component'));

    expect(app(SettingsSections::class)->find('ghost'))->not->toBeNull();
});

it('skips broken sections and logs once in production', function () {
    app()['env'] = 'production';
    Log::spy();
    app(SettingsSections::class)
        ->add(section('Bad_Key', 10))
        ->add(section('ghost-prod', 20, null, 'no-such-component'))
        ->add(section('real', 30));
    $user = User::factory()->create();

    keysFor($user);

    expect(keysFor($user))->toBe(['real']);
    Log::shouldHaveReceived('warning')->times(2);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'ghost-prod'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m): bool => str_contains($m, 'Bad_Key'));
});
