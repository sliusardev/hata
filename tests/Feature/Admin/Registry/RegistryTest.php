<?php

use App\Models\User;
use Hata\Support\Admin\Registry\Entry;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Registry\FakeEntry;
use Tests\Fixtures\Registry\FakeRegistry;

beforeEach(fn () => FakeRegistry::forgetWarnings());

/**
 * @return list<string>
 */
function registryKeys(FakeRegistry $registry): array
{
    return array_map(fn (Entry $entry): string => $entry->key, $registry->all());
}

test('entries come back by order, ties in the order added', function () {
    $registry = (new FakeRegistry)
        ->add(new FakeEntry('b', 20))
        ->add(new FakeEntry('a', 10))
        ->add(new FakeEntry('c', 20));

    expect(registryKeys($registry))->toBe(['a', 'b', 'c']);
});

test('adding a key again replaces the entry and keeps its place', function () {
    $registry = (new FakeRegistry)
        ->add(new FakeEntry('a', 10, label: 'x'))
        ->add(new FakeEntry('b', 10))
        ->add(new FakeEntry('a', 10, label: 'y'));

    expect(registryKeys($registry))->toBe(['a', 'b'])
        ->and($registry->find('a')->label)->toBe('y');
});

test('modify changes an entry when read, even if called before the add', function () {
    $registry = (new FakeRegistry)
        ->modify('a', fn (FakeEntry $entry): FakeEntry => $entry->with(order: 5))
        ->add(new FakeEntry('a', 30))
        ->add(new FakeEntry('b', 10));

    expect(registryKeys($registry))->toBe(['a', 'b'])
        ->and($registry->find('a')->order)->toBe(5);
});

test('remove hides a key whatever the order of calls', function () {
    $removedFirst = (new FakeRegistry)->remove('a')->add(new FakeEntry('a'));
    $removedAfter = (new FakeRegistry)->add(new FakeEntry('a'))->remove('a');

    expect(registryKeys($removedFirst))->toBe([])
        ->and(registryKeys($removedAfter))->toBe([])
        ->and($removedAfter->has('a'))->toBeFalse()
        ->and($removedAfter->find('a'))->toBeNull();
});

test('modify or remove of a missing key is skipped and reported', function () {
    $registry = (new FakeRegistry)
        ->add(new FakeEntry('a'))
        ->modify('zzz', fn (FakeEntry $entry): FakeEntry => $entry->with(order: 1))
        ->remove('yyy');

    expect(registryKeys($registry))->toBe(['a'])
        ->and($registry->unmatched())->toBe(['modify [zzz]', 'remove [yyy]']);
});

test('entriesFor filters by ability, closure and null', function () {
    seedRolesAndPermissions();
    $user = User::factory()->withPermissions(['users.view'])->create();

    $registry = (new FakeRegistry)
        ->add(new FakeEntry('ability', 10, 'users.view'))
        ->add(new FakeEntry('closure', 20, fn (): bool => false))
        ->add(new FakeEntry('open', 30));

    expect(array_map(fn (Entry $entry): string => $entry->key, $registry->entriesFor($user)))
        ->toBe(['ability', 'open']);
});

test('a bad key throws in testing', function () {
    $registry = (new FakeRegistry)->add(new FakeEntry('Bad_Key'));

    expect(fn () => $registry->all())->toThrow(
        LogicException::class,
        'The fake entry [Bad_Key] is left out: its key is not lowercase letters and digits joined by hyphens or dots.',
    );
});

test('a bad key is skipped and logged once in production', function () {
    app()->detectEnvironment(fn () => 'production');
    Log::spy();

    $registry = (new FakeRegistry)->add(new FakeEntry('Bad_Key'))->add(new FakeEntry('good'));

    $registry->all();

    expect(registryKeys($registry))->toBe(['good']);
    Log::shouldHaveReceived('warning')->once();
});

test('registrations survive provider order', function () {
    $registry = new FakeRegistry;

    // A site's provider, booted before Hata's.
    $registry->remove('a')->modify('b', fn (FakeEntry $entry): FakeEntry => $entry->with(label: 'changed'));

    // Hata's own registrations.
    $registry->add(new FakeEntry('a'))->add(new FakeEntry('b'));

    expect(registryKeys($registry))->toBe(['b'])
        ->and($registry->find('b')->label)->toBe('changed')
        ->and($registry->unmatched())->toBe([]);
});

test('dotted keys are valid', function () {
    $registry = (new FakeRegistry)->add(new FakeEntry('content.posts'));

    expect(registryKeys($registry))->toBe(['content.posts']);
});
