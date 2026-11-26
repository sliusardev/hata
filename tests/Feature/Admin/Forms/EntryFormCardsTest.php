<?php

use App\Models\User;
use Hata\Admin;
use Hata\Models\Entry;
use Hata\Support\Admin\Forms\FormCard;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Fixtures\Forms\SubtitleExtension;

beforeEach(function () {
    seedRolesAndPermissions();
    SubtitleExtension::register();
    SubtitleExtension::$saves = [];
    SubtitleExtension::$fail = false;
    $this->actingAs(User::factory()->withRole('editor')->create());
});

function subtitleCard(string $key = 'subtitle', array $overrides = []): FormCard
{
    return new FormCard(...['key' => $key, 'extension' => SubtitleExtension::class, ...$overrides]);
}

function cardCreateForm(): Testable
{
    return Livewire::test('hata::admin.content.create', ['type' => 'pages']);
}

function cardEditForm(Entry $entry): Testable
{
    return Livewire::test('hata::admin.content.edit', ['type' => 'pages', 'entry' => $entry]);
}

test('without a registered card the form holds no card values and draws no card', function () {
    $html = cardCreateForm()->assertSet('extensions', [])->html();

    expect($html)->not->toContain('data-test="form-card-')->not->toContain('extensions.');
});

test('a card renders below the fields in main and after Hata\'s cards in side', function () {
    Admin::formCards('content.pages')->add(subtitleCard())->add(subtitleCard('aside', ['area' => 'side']));

    $html = cardCreateForm()->assertSet('extensions', ['subtitle' => ['text' => ''], 'aside' => ['text' => '']])->html();

    $main = strpos($html, 'data-test="form-card-subtitle"');
    $side = strpos($html, 'data-test="form-card-aside"');

    expect($main)->toBeInt()->toBeGreaterThan(strpos($html, 'data-test="seo-preview"'))->toBeLessThan(strpos($html, 'wire:model="form.status"'))
        ->and($side)->toBeInt()->toBeGreaterThan(strpos($html, 'data-test="entry-parent"'))
        ->and($html)->toContain('wire:model="extensions.subtitle.text"')->toContain('wire:model="extensions.aside.text"');
});

test('on create the card saves the new record inside the transaction, then the page redirects', function () {
    Admin::formCards('content.pages')->add(subtitleCard());
    $level = DB::transactionLevel();

    $form = cardCreateForm()
        ->set('form.title', 'About')->set('form.slug', 'about')
        ->set('extensions.subtitle.text', 'Who we are')
        ->set('extensions.subtitle.extra', 'not declared')
        ->call('save')
        ->assertHasNoErrors();

    $entry = Entry::query()->where('path', 'about')->firstOrFail();

    $form->assertRedirect(route('admin.content.edit', ['type' => 'pages', 'entry' => $entry->id]));

    expect(SubtitleExtension::$saves)->toHaveCount(1)
        ->and(SubtitleExtension::$saves[0][0])->toBe($entry->id)
        ->and(SubtitleExtension::$saves[0][1])->toBe(['text' => 'Who we are'])
        ->and(SubtitleExtension::$saves[0][2])->toBeGreaterThan($level)
        ->and(DB::table('entry_subtitles')->where('entry_id', $entry->id)->value('subtitle'))->toBe('Who we are');
});

test('on edit the card is filled from the record, its values persist and the page stays', function () {
    Admin::formCards('content.pages')->add(subtitleCard());
    $entry = Entry::factory()->published()->create(['type' => 'pages', 'title' => 'About', 'slug' => 'about', 'path' => 'about']);
    DB::table('entry_subtitles')->insert(['entry_id' => $entry->id, 'subtitle' => 'Old']);

    cardEditForm($entry)
        ->assertSet('extensions.subtitle.text', 'Old')
        ->set('extensions.subtitle.text', 'New')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertDispatched('entry-saved')
        ->assertSet('extensions.subtitle.text', 'New');

    expect(DB::table('entry_subtitles')->where('entry_id', $entry->id)->value('subtitle'))->toBe('New')
        ->and(cardEditForm($entry->fresh())->get('extensions.subtitle.text'))->toBe('New');
});

test('a card invalid, the entry valid writes nothing', function () {
    Admin::formCards('content.pages')->add(subtitleCard());

    cardCreateForm()
        ->set('form.title', 'About')->set('form.slug', 'about')
        ->set('extensions.subtitle.text', '')
        ->call('save')
        ->assertHasErrors(['extensions.subtitle.text'])
        ->assertHasNoErrors('form.title')
        ->assertDispatched('entry-form-invalid')
        ->assertNoRedirect();

    expect(Entry::query()->count())->toBe(0)
        ->and(SubtitleExtension::$saves)->toBe([]);
});

test('a card invalid and the entry invalid show both errors and write nothing', function () {
    Admin::formCards('content.pages')->add(subtitleCard());

    $form = cardCreateForm()
        ->set('form.title', '')
        ->set('extensions.subtitle.text', '')
        ->call('save')
        ->assertHasErrors(['extensions.subtitle.text', 'form.title'])
        ->assertNoRedirect();

    expect($form->html())->toContain(__('validation.required', ['attribute' => 'text']))
        ->and(Entry::query()->count())->toBe(0)
        ->and(SubtitleExtension::$saves)->toBe([]);
});

test('an exception in a card\'s save rolls the record back', function () {
    Admin::formCards('content.pages')->add(subtitleCard());
    SubtitleExtension::$fail = true;

    $form = cardCreateForm()
        ->set('form.title', 'About')->set('form.slug', 'about')
        ->set('extensions.subtitle.text', 'Who we are');

    expect(fn () => $form->call('save'))->toThrow(RuntimeException::class, 'The subtitle could not be saved.');

    expect(Entry::query()->count())->toBe(0)
        ->and(DB::table('entry_subtitles')->count())->toBe(0);
});

test('a card hidden by its permission neither renders nor saves, even when its values are posted', function () {
    Admin::formCards('content.pages')
        ->add(subtitleCard('secret', ['permission' => fn (): bool => false]))
        ->add(subtitleCard('admins', ['permission' => 'users.delete']));

    $form = cardCreateForm()->assertSet('extensions', []);

    expect($form->html())->not->toContain('form-card-secret')->not->toContain('extensions.secret')->not->toContain('form-card-admins');

    $form->set('form.title', 'About')->set('form.slug', 'about')
        ->set('extensions.secret.text', 'forged')
        ->set('extensions.admins.text', 'forged')
        ->call('save')
        ->assertHasNoErrors();

    expect(Entry::query()->count())->toBe(1)
        ->and(SubtitleExtension::$saves)->toBe([])
        ->and(DB::table('entry_subtitles')->count())->toBe(0);
});

test('a card whose extension is not a FormExtension, or whose area is unknown, is a registration mistake', function () {
    Admin::formCards('content.pages')->add(new FormCard('broken', stdClass::class));

    expect(fn () => cardCreateForm())->toThrow('The form card [broken] is left out: its extension [stdClass] is not a class extending');

    Admin::formCards('content.pages')->add(subtitleCard('broken', ['area' => 'footer']));

    expect(fn () => cardCreateForm())->toThrow('its area [footer] is not main or side');
});
