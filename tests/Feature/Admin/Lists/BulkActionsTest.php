<?php

use App\Models\User;
use Hata\Admin;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Support\Admin\Lists\BulkAction;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewException;
use Livewire\Livewire;
use Tests\Fixtures\Lists\ArchiveBulkHandler;

beforeEach(function () {
    seedRolesAndPermissions();
    ArchiveBulkHandler::$calls = [];
    ArchiveBulkHandler::$message = 'Archived <b>all</b>';
    ArchiveBulkHandler::$throw = null;
    $this->actingAs(User::factory()->withRole('admin')->create(['name' => 'Chief']));
});

function archiveBulk(array $overrides = []): BulkAction
{
    return new BulkAction(...[
        'key' => 'archive',
        'label' => 'Archive them',
        'icon' => 'archive-box',
        'handler' => ArchiveBulkHandler::class,
        ...$overrides,
    ]);
}

function bulkPage(string $title): Entry
{
    return Entry::factory()->published()->create(['type' => 'pages', 'title' => $title, 'slug' => str($title)->slug()->toString(), 'path' => str($title)->slug()->toString()]);
}

test('a bulk action gets exactly the selected records of the view and toasts its message', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk());
    $one = bulkPage('One');
    $two = bulkPage('Two');
    bulkPage('Three');
    $post = Entry::factory()->published()->create(['type' => 'posts', 'title' => 'Elsewhere', 'slug' => 'elsewhere', 'path' => 'elsewhere']);

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->set('selected', [(string) $one->id, (string) $two->id, (string) $post->id, 'junk'])
        ->call('runBulkAction', 'archive')
        ->assertSet('selected', [])
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Archived <b>all</b>');

    expect(ArchiveBulkHandler::$calls)->toHaveCount(1);
    expect(collect(ArchiveBulkHandler::$calls[0])->sort()->values()->all())->toBe([$one->id, $two->id]);
});

test('an empty selection calls no handler', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk());

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])->call('runBulkAction', 'archive');

    expect(ArchiveBulkHandler::$calls)->toBe([]);
});

test('a danger action always confirms, and so does one with a confirmation text', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk(['danger' => true]));
    Admin::bulkActions('content.pages')->add(archiveBulk(['key' => 'plain', 'confirm' => 'Sure?']));
    Admin::bulkActions('content.pages')->add(archiveBulk(['key' => 'quick']));
    $entry = bulkPage('One');

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->set('selected', [(string) $entry->id])
        ->call('confirmBulkAction', 'archive')
        ->assertSet('showBulkActionModal', true);

    expect(ArchiveBulkHandler::$calls)->toBe([]);

    $component->call('runPendingBulkAction')->assertSet('showBulkActionModal', false)->assertSet('bulkActionKey', null);
    expect(ArchiveBulkHandler::$calls)->toHaveCount(1);

    $component->set('selected', [(string) $entry->id])->call('confirmBulkAction', 'plain')->assertSet('showBulkActionModal', true)->assertSee('Sure?');
    $component->set('showBulkActionModal', false)->assertSet('bulkActionKey', null)->assertSet('showBulkActionModal', false);
    expect(ArchiveBulkHandler::$calls)->toHaveCount(1);

    $component->set('selected', [(string) $entry->id])->call('confirmBulkAction', 'quick')->assertSet('showBulkActionModal', false);
    expect(ArchiveBulkHandler::$calls)->toHaveCount(2);
});

test('an action is not shown in the trash by default, and a permission hides it', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk());
    Admin::bulkActions('content.pages')->add(archiveBulk(['key' => 'locked', 'permission' => 'nobody.can']));
    Admin::bulkActions('content.pages')->add(archiveBulk(['key' => 'trashy', 'visibleIn' => fn ($context): bool => $context->isTrash()]));
    bulkPage('Live');
    $entry = bulkPage('One');
    $entry->delete();

    $html = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('tab', 'trash')->html();

    expect($html)->toContain('bulk-action-trashy')->not->toContain('bulk-action-archive')->not->toContain('bulk-action-locked');

    $live = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->html();
    expect($live)->toContain('bulk-action-archive')->not->toContain('bulk-action-locked')->not->toContain('bulk-action-trashy');
});

test('an action that is not shown cannot be run', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk(['permission' => 'nobody.can']));
    $entry = bulkPage('One');

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->set('selected', [(string) $entry->id])
        ->call('runBulkAction', 'archive')
        ->assertNotFound();

    expect(ArchiveBulkHandler::$calls)->toBe([]);
});

test('the users list has checkboxes and the bar only with a visible bulk action', function () {
    $without = Livewire::test('hata::admin.users.index')->html();

    expect($without)->not->toContain('data-test="bulk-bar"')->not->toContain('type="checkbox"')->not->toContain('data-row-check');

    Admin::bulkActions('users')->add(archiveBulk());
    $with = Livewire::test('hata::admin.users.index')->html();

    expect($with)->toContain('data-test="bulk-bar"')->toContain('data-row-check')->toContain('data-test="select-all"')->toContain('bulk-action-archive');
});

test('a bulk action on users gets the selected users and the selection clears on search', function () {
    Admin::bulkActions('users')->add(archiveBulk());
    $other = User::factory()->create();
    $actor = auth()->user();

    $component = Livewire::test('hata::admin.users.index')
        ->set('selected', [(string) $other->id, (string) $actor->id])
        ->set('search', 'x')
        ->assertSet('selected', [])
        ->set('selected', [(string) $other->id])
        ->call('runBulkAction', 'archive');

    expect(ArchiveBulkHandler::$calls)->toBe([[$other->id]]);
});

test('a bulk handler refuses with a ProtectedActionException or a ValidationException, shown as a danger toast', function (string $case) {
    ArchiveBulkHandler::$throw = $case === 'refusal'
        ? new ProtectedActionException('Not these')
        : ValidationException::withMessages(['reason' => 'Not these']);
    Admin::bulkActions('content.pages')->add(archiveBulk());
    $one = bulkPage('One');

    Livewire::test('hata::admin.content.index', ['type' => 'pages'])
        ->set('selected', [(string) $one->id])
        ->call('runBulkAction', 'archive')
        ->assertOk()
        ->assertSet('selected', [])
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['slots']['text'] ?? null) === 'Not these' && ($params['dataset']['variant'] ?? null) === 'danger');
})->with(['refusal', 'validation']);

test('a user who lost the list\'s permission cannot run a bulk action', function () {
    Admin::bulkActions('content.pages')->add(archiveBulk());
    $user = User::factory()->withPermissions(['admin.access', 'pages.view'])->create();
    $this->actingAs($user);
    $one = bulkPage('One');

    $component = Livewire::test('hata::admin.content.index', ['type' => 'pages'])->set('selected', [(string) $one->id]);

    $user->revokePermissionTo('pages.view');

    $component->call('runBulkAction', 'archive')->assertForbidden();

    expect(ArchiveBulkHandler::$calls)->toBe([]);
});

test('a bulk action handler class without __invoke is a registration mistake', function () {
    Admin::bulkActions('users')->add(archiveBulk(['handler' => stdClass::class]));

    expect(fn () => Livewire::test('hata::admin.users.index'))->toThrow(ViewException::class, 'has no __invoke');
});

test('the users list clears its selection on a page change', function () {
    Admin::bulkActions('users')->add(archiveBulk());
    User::factory()->count(30)->create();

    Livewire::test('hata::admin.users.index')
        ->set('selected', ['1', '2'])
        ->call('gotoPage', 2)
        ->assertSet('selected', []);
});
