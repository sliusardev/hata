<?php

use App\Models\User;
use Hata\Admin;
use Hata\Models\Entry;
use Hata\Support\Admin\Lists\RowAction;
use Hata\Testing\AssertsListQueries;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Fixtures\Lists\ArchiveHandler;

uses(AssertsListQueries::class);

beforeEach(function () {
    seedRolesAndPermissions();
    ArchiveHandler::$calls = [];
    ArchiveHandler::$message = 'Archived <b>it</b>';
    $this->actingAs(User::factory()->withRole('admin')->create(['name' => 'Chief']));
});

function archiveAction(array $overrides = []): RowAction
{
    return RowAction::handler(...[
        'key' => 'archive',
        'label' => 'Archive it',
        'icon' => 'archive-box',
        'handler' => ArchiveHandler::class,
        ...$overrides,
    ]);
}

function pageEntry(string $title = 'About', array $attributes = []): Entry
{
    return Entry::factory()->published()->create(['type' => 'pages', 'title' => $title, 'slug' => str($title)->slug()->toString(), 'path' => str($title)->slug()->toString(), ...$attributes]);
}

test('a link action renders in each row with its url', function () {
    Admin::rowActions('content.pages')->add(RowAction::link('peek', 'Peek at it', 'eye', fn (Entry $entry): string => 'https://example.test/peek/'.$entry->id, newTab: true));
    $one = pageEntry('One');
    $two = pageEntry('Two');

    $html = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->html();

    expect($html)->toContain('Peek at it')->toContain('https://example.test/peek/'.$one->id)->toContain('https://example.test/peek/'.$two->id);
});

test('a handler action runs, toasts its message escaped and reaches the handler with the record', function () {
    Admin::rowActions('content.pages')->add(archiveAction());
    $entry = pageEntry();

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->call('runRowAction', 'archive', $entry->id)
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Archived <b>it</b>' && ($params['dataset']['variant'] ?? null) === 'success');

    expect(ArchiveHandler::$calls)->toBe([[Entry::class, $entry->id, false]]);
});

test('a handler returning null shows no toast', function () {
    ArchiveHandler::$message = null;
    Admin::rowActions('content.pages')->add(archiveAction());
    $entry = pageEntry();

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->call('runRowAction', 'archive', $entry->id)
        ->assertNotDispatched('toast-show');

    expect(ArchiveHandler::$calls)->toHaveCount(1);
});

test('an action with a confirmation opens the dialog first and runs on confirm', function () {
    Admin::rowActions('content.pages')->add(archiveAction(['confirm' => 'Really archive this?', 'danger' => true]));
    $entry = pageEntry();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->call('confirmRowAction', 'archive', $entry->id)
        ->assertSet('showRowActionModal', true);

    expect(ArchiveHandler::$calls)->toBe([])->and($component->html())->toContain('Really archive this?');

    $component->call('runPendingRowAction')->assertSet('showRowActionModal', false);

    expect(ArchiveHandler::$calls)->toHaveCount(1);
});

test('an action without a confirmation runs at once', function () {
    Admin::rowActions('content.pages')->add(archiveAction());
    $entry = pageEntry();

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->call('confirmRowAction', 'archive', $entry->id)->assertSet('showRowActionModal', false);

    expect(ArchiveHandler::$calls)->toHaveCount(1);
});

test('a record outside the view is a 404 and the handler never runs', function (string $case) {
    Admin::rowActions('content.*')->add(archiveAction());
    $target = match ($case) {
        'other type' => Entry::factory()->published()->create(['type' => 'products', 'title' => 'Shoe', 'slug' => 'shoe', 'path' => 'shoe']),
        'trashed on all' => tap(pageEntry('Gone'))->delete(),
        'other language' => pageEntry('Deutsch', ['locale' => 'de']),
    };

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages']);

    if ($case === 'other language') {
        $component->set('locale', 'en');
    }

    $component->call('runRowAction', 'archive', $target->id)->assertNotFound();

    $second = Livewire::test('hata::admin.content.index', ['type' => 'pages']);
    $other = $case === 'other language' ? $second->set('locale', 'en') : $second;
    $other->call('confirmRowAction', 'archive', $target->id)->assertNotFound();

    expect(ArchiveHandler::$calls)->toBe([]);
})->with(['other type', 'trashed on all', 'other language']);

test('runRowAction answers 404 for a record outside the view', function () {
    Admin::rowActions('content.pages')->add(archiveAction());
    $trashed = tap(pageEntry('Gone'))->delete();

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->call('runRowAction', 'archive', $trashed->id)
        ->assertNotFound();

    expect(ArchiveHandler::$calls)->toBe([]);
});

test('an action the user may not take on a record shows no item and is a 403', function () {
    Admin::rowActions('content.pages')->add(archiveAction(['permission' => fn ($user, Entry $entry): bool => $entry->title !== 'Locked']));
    $open = pageEntry('Open');
    $locked = pageEntry('Locked');

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages']);

    expect($component->html())->toContain("confirmRowAction('archive', {$open->id})")->not->toContain("confirmRowAction('archive', {$locked->id})");

    $component->call('runRowAction', 'archive', $locked->id)->assertForbidden();

    expect(ArchiveHandler::$calls)->toBe([]);
});

test('a string permission is checked through the gate with the record', function () {
    Gate::define('archive-it', fn ($user, Entry $entry): bool => $entry->title === 'Open');
    Admin::rowActions('content.pages')->add(archiveAction(['permission' => 'archive-it']));
    $open = pageEntry('Open');
    $locked = pageEntry('Locked');

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages']);

    expect($component->html())->toContain("confirmRowAction('archive', {$open->id})")->not->toContain("confirmRowAction('archive', {$locked->id})");
    $component->call('runRowAction', 'archive', $locked->id)->assertForbidden();
});

test('a site action alone still gets the menu', function () {
    Admin::rowActions('content.pages')->add(archiveAction());
    $entry = pageEntry();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    $html = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->html();

    expect($html)->toContain("confirmRowAction('archive', {$entry->id})")->toContain('entry-actions-'.$entry->id);
});

test('row actions are not shown in the trash by default, and load with onlyTrashed when visibleIn allows it', function () {
    Admin::rowActions('content.pages')->add(archiveAction());
    Admin::rowActions('content.pages')->add(archiveAction(['key' => 'archive-trash', 'visibleIn' => fn () => true]));
    $trashed = tap(pageEntry('Gone'))->delete();

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('tab', 'trash');

    expect($component->html())->toContain("confirmRowAction('archive-trash', {$trashed->id})")->not->toContain("confirmRowAction('archive', {$trashed->id})");

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('tab', 'trash')->call('runRowAction', 'archive', $trashed->id)->assertNotFound();
    expect(ArchiveHandler::$calls)->toBe([]);

    $component->call('runRowAction', 'archive-trash', $trashed->id);
    expect(ArchiveHandler::$calls)->toBe([[Entry::class, $trashed->id, true]]);
});

test('an unknown action key is a 404 and one the user may not take is a 403', function () {
    Admin::rowActions('content.pages')->add(archiveAction(['permission' => 'roles.edit']));
    $entry = pageEntry();
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->call('runRowAction', 'missing', $entry->id)->assertNotFound();
    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->call('runRowAction', 'archive', $entry->id)->assertForbidden();

    expect(ArchiveHandler::$calls)->toBe([]);
});

test('a link action cannot be run as a handler', function () {
    Admin::rowActions('content.pages')->add(RowAction::link('peek', 'Peek', 'eye', fn (Entry $entry): string => '/x'));
    $entry = pageEntry();

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->call('runRowAction', 'peek', $entry->id)->assertNotFound();
});

test('a list without registered row actions has no action dialog', function () {
    pageEntry();

    expect(Livewire::test('hata::admin.content.index', ['type' => 'pages'])->html())->not->toContain('row-action-modal')->not->toContain('confirmRowAction');
    expect(Livewire::test('hata::admin.users.index')->html())->not->toContain('row-action-modal')->not->toContain('confirmRowAction');
});

test('row actions cost no query per row on the content list', function () {
    Admin::rowActions('content.pages')->add(archiveAction());

    $this->assertListQueriesStable(
        fn (int $count) => Entry::factory()->count($count)->published()->create(['type' => 'pages']),
        fn () => Livewire::test('hata::admin.content.index', ['type' => 'pages'])->html(),
    );
});

test('the users list renders and runs row actions', function () {
    Admin::rowActions('users')->add(RowAction::link('mail', 'Send mail', 'envelope', fn (User $user): string => 'mailto:'.$user->email));
    Admin::rowActions('users')->add(archiveAction());
    $other = User::factory()->create(['name' => 'Other', 'email' => 'other@example.com']);

    $component = Livewire::test('hata::admin.users.index');

    expect($component->html())->toContain('mailto:other@example.com')->toContain("confirmRowAction('archive', {$other->id})");

    $component->call('runRowAction', 'archive', $other->id)->assertDispatched('toast-show');

    expect(ArchiveHandler::$calls)->toBe([[User::class, $other->id, false]]);
});

test('the users list answers 404 for an id that is not a user and 403 for a refused record', function () {
    Admin::rowActions('users')->add(archiveAction(['permission' => fn ($user, User $record): bool => $record->name !== 'Locked']));
    $locked = User::factory()->create(['name' => 'Locked']);

    Livewire::test('hata::admin.users.index')->call('runRowAction', 'archive', 999999)->assertNotFound();
    Livewire::test('hata::admin.users.index')->call('runRowAction', 'archive', $locked->id)->assertForbidden();

    expect(ArchiveHandler::$calls)->toBe([]);
});

test('row actions cost no query per row on the users list', function () {
    Admin::rowActions('users')->add(archiveAction());

    $this->assertListQueriesStable(
        fn (int $count) => User::factory()->count($count)->create(),
        fn () => Livewire::test('hata::admin.users.index')->html(),
    );
});

test('a row action with a bad key or a missing handler class is a registration mistake', function () {
    Admin::rowActions('users')->add(archiveAction(['key' => 'Bad Key']));

    expect(fn () => Livewire::test('hata::admin.users.index'))->toThrow(ViewException::class, 'Bad Key');
});

test('a row action can be removed and modified like other entries', function () {
    Admin::rowActions('users')->add(archiveAction())->modify('archive', fn (RowAction $action) => $action->with(label: 'Renamed'));
    expect(Livewire::test('hata::admin.users.index')->html())->toContain('Renamed');

    Admin::rowActions('users')->remove('archive');
    expect(Livewire::test('hata::admin.users.index')->html())->not->toContain('Renamed');
});
