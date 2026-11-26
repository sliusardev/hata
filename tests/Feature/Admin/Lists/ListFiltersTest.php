<?php

use App\Models\User;
use Hata\Admin;
use Hata\Models\Entry;
use Hata\Support\Admin\Lists\TableFilter;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('admin')->create(['name' => 'Chief']));
});

function stockFilter(array $overrides = []): TableFilter
{
    return new TableFilter(...[
        'key' => 'stock',
        'label' => 'Stock',
        'options' => fn () => ['out' => 'Out of stock', 'in' => 'In stock'],
        'apply' => fn ($query, string $value) => $query->where('title', $value === 'out' ? 'like' : 'not like', 'Out%'),
        ...$overrides,
    ]);
}

function filterEntries(): void
{
    Entry::factory()->published()->create(['type' => 'pages', 'title' => 'Out one', 'slug' => 'out-one', 'path' => 'out-one']);
    Entry::factory()->published()->create(['type' => 'pages', 'title' => 'Fine one', 'slug' => 'fine-one', 'path' => 'fine-one']);
}

test('a registered filter narrows the content list and is in the query string', function () {
    Admin::filters('content.pages')->add(stockFilter());
    filterEntries();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('filters.stock', 'out');

    expect($component->instance()->entries->pluck('title')->all())->toBe(['Out one'])
        ->and($component->html())->toContain('Stock')->toContain('Out of stock');

    Livewire::withQueryParams(['filter' => ['stock' => 'in']])->test('hata::admin.content.index', ['type' => 'pages'])
        ->assertSet('filters', ['stock' => 'in']);
});

test('a value that is not among the options is ignored and apply is never called', function () {
    $called = false;
    Admin::filters('content.pages')->add(stockFilter(['apply' => function () use (&$called) {
        $called = true;
    }]));
    filterEntries();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('filters.stock', 'bogus');

    expect($component->instance()->entries->total())->toBe(2)->and($called)->toBeFalse();
});

test('clearing the filters empties a registered one', function () {
    Admin::filters('content.pages')->add(stockFilter());
    filterEntries();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('filters.stock', 'out');
    expect($component->instance()->isFiltered())->toBeTrue();

    $component->call('clearFilters')->assertSet('filters', []);
});

test('a filter hidden by permission is ignored even when it is in the url', function () {
    Admin::filters('content.pages')->add(stockFilter(['permission' => 'roles.edit']));
    filterEntries();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    $component = Livewire::withQueryParams(['filter' => ['stock' => 'out']])->test('hata::admin.content.index', ['type' => 'pages']);

    expect($component->instance()->entries->total())->toBe(2)->and($component->html())->not->toContain('Out of stock');
});

test('the users list filter works the same', function () {
    Admin::filters('users')->add(new TableFilter(
        key: 'mail',
        label: 'Mail',
        options: fn () => ['x' => 'X mail'],
        apply: fn ($query, string $value) => $query->where('email', 'like', 'x%'),
    ));
    User::factory()->create(['name' => 'Xavier', 'email' => 'x@example.com']);

    $component = Livewire::test('hata::admin.users.index')->set('filters.mail', 'x');
    expect($component->instance()->users->pluck('name')->all())->toBe(['Xavier'])->and($component->html())->toContain('X mail');

    $component->set('filters.mail', 'nope');
    expect($component->instance()->users->total())->toBe(2);

    $component->set('filters.mail', 'x')->call('clearFilters')->assertSet('filters', []);
});

test('a filter hidden in a tab by visibleIn is not applied there', function () {
    Admin::filters('content.pages')->add(stockFilter(['visibleIn' => fn ($context) => ! $context->isTrash()]));
    filterEntries();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('filters.stock', 'out')->set('tab', 'trash');

    expect($component->html())->not->toContain('Out of stock');
});

test('lists without a registered filter draw none', function () {
    expect(Livewire::test('hata::admin.users.index')->html())->not->toContain('wire:model.live="filters.');
});
