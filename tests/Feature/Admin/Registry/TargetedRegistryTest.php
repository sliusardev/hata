<?php

use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CategoriesType;
use Hata\Support\Content\Types\PostsType;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Registry\FakeEntry;
use Tests\Fixtures\Registry\FakeTargetedRegistry;

beforeEach(function () {
    FakeTargetedRegistry::forgetWarnings();
    app(ContentTypes::class)->register(new PostsType)->register(new CategoriesType);
});

/**
 * @return list<string>
 */
function targetedKeys(FakeTargetedRegistry $registry, string $target): array
{
    return array_map(fn (FakeEntry $entry): string => $entry->key, $registry->resolve($target)->all());
}

test('an entry added to content.* shows on every content target and not on users', function () {
    $registry = (new FakeTargetedRegistry);
    $registry->on('content.*')->add(new FakeEntry('a'));

    expect(targetedKeys($registry, 'content.pages'))->toBe(['a'])
        ->and(targetedKeys($registry, 'content.categories'))->toBe(['a'])
        ->and(targetedKeys($registry, 'users'))->toBe([]);
});

test('a narrower target overrides the same key', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('content.*')->add(new FakeEntry('a', label: 'wide'));
    $registry->on('content.posts')->add(new FakeEntry('a', label: 'narrow'));

    expect($registry->resolve('content.posts')->find('a')->label)->toBe('narrow')
        ->and($registry->resolve('content.pages')->find('a')->label)->toBe('wide');
});

test('a narrower target can remove or modify an inherited entry for itself only', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('content.*')->add(new FakeEntry('author'))->add(new FakeEntry('date', label: 'old'));
    $registry->on('content.posts')->remove('author')->modify('date', fn (FakeEntry $entry): FakeEntry => $entry->with(label: 'new'));

    expect(targetedKeys($registry, 'content.posts'))->toBe(['date'])
        ->and($registry->resolve('content.posts')->find('date')->label)->toBe('new')
        ->and(targetedKeys($registry, 'content.pages'))->toBe(['author', 'date'])
        ->and($registry->resolve('content.pages')->find('date')->label)->toBe('old');
});

test('group targets reach taxonomies or records only', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('content.@taxonomies')->add(new FakeEntry('t'));
    $registry->on('content.@records')->add(new FakeEntry('r'));

    expect(targetedKeys($registry, 'content.categories'))->toBe(['t'])
        ->and(targetedKeys($registry, 'content.pages'))->toBe(['r'])
        ->and($registry->chain('content.categories'))->toBe(['content.*', 'content.@taxonomies', 'content.categories'])
        ->and($registry->chain('users'))->toBe(['users']);
});

test('the order of calls does not matter', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('content.posts')->remove('a');
    $registry->on('content.*')->add(new FakeEntry('a'));

    expect(targetedKeys($registry, 'content.posts'))->toBe([])
        ->and(targetedKeys($registry, 'content.pages'))->toBe(['a']);
});

test('several targets at once', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on(['users', 'content.*'])->add(new FakeEntry('a'));

    expect(targetedKeys($registry, 'users'))->toBe(['a'])
        ->and(targetedKeys($registry, 'content.posts'))->toBe(['a'])
        ->and($registry->targets())->toBe(['users', 'content.*']);
});

test('a call made after a resolve is seen by the next one', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('users')->add(new FakeEntry('a'));
    $registry->resolve('users');
    $registry->on('users')->add(new FakeEntry('b'));

    expect(targetedKeys($registry, 'users'))->toBe(['a', 'b']);
});

test('a bad target is a registration mistake', function (string $target) {
    expect(fn () => (new FakeTargetedRegistry)->on($target))
        ->toThrow(LogicException::class, "[{$target}]");
})->with(['posts', 'content.', 'content.Posts', 'content.@x', 'users.*']);

test('in production a bad target is skipped and logged once', function () {
    app()['env'] = 'production';
    Log::spy();

    $registry = new FakeTargetedRegistry;
    $registry->on('nope')->add(new FakeEntry('a'));
    $registry->on('nope');

    expect($registry->targets())->toBe([]);
    Log::shouldHaveReceived('warning')->once();
});

test('a remove on a wide target also hides the key a narrower target adds again', function () {
    $registry = new FakeTargetedRegistry;
    $registry->on('content.*')->remove('a');
    $registry->on('content.posts')->add(new FakeEntry('a'));

    expect(targetedKeys($registry, 'content.posts'))->toBe([])
        ->and($registry->resolve('content.posts')->unmatched())->toBe([]);
});
