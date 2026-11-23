<?php

use App\Models\User;
use Hata\Actions\Content\LinkTranslations;
use Hata\Actions\Content\UnlinkTranslation;
use Hata\Actions\Content\UpdateEntry;
use Hata\Exceptions\ProtectedActionException;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Models\EntryRedirect;
use Hata\Support\Content\EntryPaths;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();

    app(SiteSettings::class)->put([
        'content_locales' => [
            ['code' => 'en', 'active' => true],
            ['code' => 'uk', 'active' => true],
            ['code' => 'pl', 'active' => true],
        ],
        'content_default_locale' => 'en',
    ]);
});

function translator(): User
{
    return User::factory()->withRole('editor')->create();
}

function translationForm(Entry $entry): Testable
{
    return Livewire::test('pages::admin.content.edit', ['type' => $entry->type, 'entry' => $entry]);
}

function translationPicker(Entry $entry): Testable
{
    return Livewire::test('translation-picker', ['type' => $entry->type, 'entryId' => $entry->id]);
}

/**
 * The data the edit form would send for the record as it is.
 *
 * @return array<string, mixed>
 */
function formDataOf(Entry $entry): array
{
    $page = translationForm($entry)->instance();

    return $page->form->data($page->contentType);
}

test('a saved record takes another language on Save, and its old address leads to the new one', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->published()->create(['title' => 'About', 'slug' => 'about', 'path' => 'about']);

    $form = translationForm($page)
        ->call('changeLocale', 'uk')
        ->assertSet('locale', 'uk')
        ->assertReturned(fn (array $state): bool => $state['locale'] === 'uk' && str_ends_with($state['prefix'], '/uk/'));

    expect($page->fresh()->locale)->toBe('en');

    $form->call('save')->assertHasNoErrors();

    expect($page->fresh())->locale->toBe('uk')->path->toBe('about')
        ->and(EntryRedirect::query()->where(['type' => 'pages', 'locale' => 'en', 'path' => 'about'])->value('entry_id'))->toBe($page->id);

    auth()->logout();

    $this->get('/about')->assertRedirect(url('/uk/about'));
    $this->get('/uk/about')->assertOk()->assertSee('About');
});

test('in its new language the record goes under the parent\'s translation and gets a free slug', function () {
    $this->actingAs(translator());
    $company = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    Entry::factory()->translationOf($company, 'uk')->create(['slug' => 'kompaniia', 'path' => 'kompaniia']);
    $team = Entry::factory()->childOf($company)->create(['slug' => 'team', 'path' => 'company/team']);
    $taken = Entry::factory()->inLocale('pl')->create(['slug' => 'team', 'path' => 'team']);

    translationForm($team)->call('changeLocale', 'uk')->call('save')->assertHasNoErrors();

    expect($team->fresh())->locale->toBe('uk')->path->toBe('kompaniia/team');

    translationForm($team->fresh())
        ->call('changeLocale', 'pl')
        ->assertSet('form.parent_id', '')
        ->assertSet('form.slug', 'team-2')
        ->call('save')
        ->assertHasNoErrors();

    expect($team->fresh())->locale->toBe('pl')->path->toBe('team-2')->parent_id->toBeNull()
        ->and($taken->fresh()->path)->toBe('team');
});

test('a language the group already has is not offered, in the trash either, and the action refuses it', function () {
    $this->actingAs($actor = translator());
    $page = Entry::factory()->create();
    Entry::factory()->translationOf($page, 'uk')->create();
    Entry::factory()->translationOf($page, 'pl')->create(['deleted_at' => now()]);

    translationForm($page)
        ->call('changeLocale', 'uk')->assertReturned(null)
        ->call('changeLocale', 'pl')->assertReturned(null)
        ->assertSet('locale', 'en');

    $data = formDataOf($page);

    expect(fn () => app(UpdateEntry::class)->handle($actor, $page, $data, 'pl'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['locale'])->toBe([__('content.entry.locale_taken')]))
        ->and(fn () => app(UpdateEntry::class)->handle($actor, $page, $data, 'fr'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['locale'])->toBe([__('content.entry.locale_invalid')]))
        ->and($page->fresh()->locale)->toBe('en');
});

test('a page with pages under it keeps its language', function () {
    $this->actingAs($actor = translator());
    $parent = Entry::factory()->create(['slug' => 'parent', 'path' => 'parent']);
    Entry::factory()->childOf($parent)->create(['slug' => 'child', 'path' => 'parent/child', 'deleted_at' => now()]);

    translationForm($parent)
        ->assertDontSeeHtml('data-test="entry-locale"')
        ->assertSeeHtml('data-test="entry-locale-locked"')
        ->call('changeLocale', 'uk')
        ->assertReturned(null);

    $data = formDataOf($parent);

    expect(fn () => app(UpdateEntry::class)->handle($actor, $parent, $data, 'uk'))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['locale'])->toBe([__('content.entry.locale_has_children')]));
});

test('changing the language of a saved record needs edit and a record out of the trash', function () {
    $page = Entry::factory()->create();

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view', 'pages.create'])->create());
    translationForm($page)->call('changeLocale', 'uk')->assertForbidden();

    $this->actingAs(translator());
    $page->delete();
    translationForm($page)->call('changeLocale', 'uk')->assertForbidden();
});

test('an existing record is chosen as the translation in its language', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create(['title' => 'About']);
    $ukrainian = Entry::factory()->inLocale('uk')->create(['title' => 'Про нас']);
    $other = Entry::factory()->inLocale('uk')->create(['title' => 'Контакти']);
    $english = Entry::factory()->create(['title' => 'Contacts']);
    Entry::factory()->inLocale('uk')->create(['title' => 'У кошику', 'deleted_at' => now()]);
    $emptied = $ukrainian->entry_group_id;

    translationPicker($page)
        ->assertDontSeeHtml('data-test="link-candidate-'.$ukrainian->id.'"')
        ->call('choose', 'uk')
        ->assertSet('open', true)
        ->assertSeeHtml('data-test="link-candidate-'.$ukrainian->id.'"')
        ->assertSeeHtml('data-test="link-candidate-'.$other->id.'"')
        ->assertDontSeeHtml('data-test="link-candidate-'.$english->id.'"')
        ->assertDontSee('У кошику')
        ->set('search', 'про')
        ->assertDontSeeHtml('data-test="link-candidate-'.$other->id.'"')
        ->call('pick', $ukrainian->id)
        ->assertSet('open', false)
        ->assertDispatched('translations-changed');

    expect($ukrainian->fresh()->entry_group_id)->toBe($page->entry_group_id)
        ->and(EntryGroup::query()->whereKey($emptied)->exists())->toBeFalse()
        ->and($ukrainian->fresh()->updated_at->eq($ukrainian->updated_at))->toBeTrue();

    translationForm($page)
        ->assertSeeHtml('data-test="translation-choose-pl"')
        ->assertSeeHtml('data-test="translation-unlink-uk"')
        ->dispatch('translations-changed')
        ->assertSee('Про нас');
});

test('a record that is someone\'s translation is linked only after a second click', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create();
    $ukrainian = Entry::factory()->inLocale('uk')->create();
    Entry::factory()->translationOf($ukrainian, 'pl')->create();

    $picker = translationPicker($page)
        ->call('choose', 'uk')
        ->assertSee(__('content.link.linked_to', ['languages' => 'PL']))
        ->call('pick', $ukrainian->id)
        ->assertSet('open', true)
        ->assertSeeHtml('data-test="link-confirm"')
        ->assertSee(__('content.link.leaves', ['languages' => 'PL']));

    expect($ukrainian->fresh()->entry_group_id)->not->toBe($page->entry_group_id);

    $picker->call('cancelConfirmation')->assertSet('confirmingId', 0)->assertDontSeeHtml('data-test="link-confirm"');

    $picker->call('pick', $ukrainian->id)->assertSeeHtml('data-test="link-confirm"')->call('pick', $ukrainian->id)->assertSet('open', false);

    expect($ukrainian->fresh()->entry_group_id)->toBe($page->entry_group_id);
});

test('the picker lists twenty records and says when there are more', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create();
    Entry::factory()->inLocale('uk')->count(20)->create();

    $picker = translationPicker($page)->call('choose', 'uk')->assertDontSeeHtml('data-test="link-more"');

    Entry::factory()->inLocale('uk')->create();

    $picker->set('search', '')->call('choose', 'uk')->assertSeeHtml('data-test="link-more"');
});

test('a chosen record leaves its former translations, which stay linked to each other', function () {
    $actor = translator();
    $page = Entry::factory()->create();
    $ukrainian = Entry::factory()->inLocale('uk')->create();
    $polish = Entry::factory()->translationOf($ukrainian, 'pl')->create();
    $formerEnglish = Entry::factory()->translationOf($ukrainian, 'en')->create();
    $former = $ukrainian->entry_group_id;

    app(LinkTranslations::class)->handle($actor, $page, $ukrainian);

    expect($ukrainian->fresh()->entry_group_id)->toBe($page->entry_group_id)
        ->and($polish->fresh()->entry_group_id)->toBe($former)
        ->and($formerEnglish->fresh()->entry_group_id)->toBe($former);
});

test('records that cannot be linked are refused', function () {
    $actor = translator();
    $page = Entry::factory()->create();
    $translation = Entry::factory()->translationOf($page, 'uk')->create();
    $sameLanguage = Entry::factory()->create();
    $second = Entry::factory()->inLocale('uk')->create();
    $trashed = Entry::factory()->inLocale('pl')->create(['deleted_at' => now()]);
    $link = fn (Entry $other) => fn () => app(LinkTranslations::class)->handle($actor, $page, $other);

    expect($link($translation))->toThrow(ProtectedActionException::class, __('content.link.invalid'))
        ->and($link($trashed))->toThrow(ProtectedActionException::class, __('content.link.invalid'))
        ->and($link($sameLanguage))->toThrow(ProtectedActionException::class, __('content.link.overlap'))
        ->and($link($second))->toThrow(ProtectedActionException::class, __('content.link.overlap'))
        ->and($second->fresh()->entry_group_id)->not->toBe($page->entry_group_id);
});

test('choosing a translation needs edit, and only for a language the record lacks', function () {
    $page = Entry::factory()->create();
    Entry::factory()->translationOf($page, 'uk')->create();
    $polish = Entry::factory()->inLocale('pl')->create();

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view', 'pages.create'])->create());
    translationPicker($page)->call('choose', 'pl')->assertForbidden();
    translationPicker($page)->call('pick', $polish->id)->assertForbidden();

    $this->actingAs(translator());
    translationPicker($page)->call('choose', 'uk')->assertNotFound();
    translationPicker($page)->call('choose', 'fr')->assertNotFound();
    translationPicker($page)->call('pick', 999)->assertNotFound();
});

test('a translation is unlinked after a confirmation and takes a copy of the shared values', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create();
    $page->group->update(['shared' => ['price' => 10]]);
    $ukrainian = Entry::factory()->translationOf($page, 'uk')->create(['title' => 'Про нас']);
    $polish = Entry::factory()->translationOf($page, 'pl')->create();

    translationForm($page)
        ->call('confirmUnlink', $ukrainian->id)
        ->assertSet('showUnlinkModal', true)
        ->assertSee(__('content.link.unlink_confirm', ['title' => 'Про нас']))
        ->call('unlink')
        ->assertSet('showUnlinkModal', false)
        ->assertSeeHtml('data-test="translation-choose-uk"');

    $ukrainian->refresh();

    expect($ukrainian->entry_group_id)->not->toBe($page->entry_group_id)
        ->and($ukrainian->group->shared)->toBe(['price' => 10])
        ->and($polish->fresh()->entry_group_id)->toBe($page->entry_group_id);
});

test('the record itself can leave its translations', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create();
    $ukrainian = Entry::factory()->translationOf($page, 'uk')->create();
    $polish = Entry::factory()->translationOf($page, 'pl')->create();
    $group = $page->entry_group_id;

    translationForm($page)
        ->assertSeeHtml('data-test="unlink-translation"')
        ->call('confirmUnlink', $page->id)
        ->call('unlink')
        ->assertDontSeeHtml('data-test="translation-unlink-uk"');

    expect($page->fresh()->entry_group_id)->not->toBe($group)
        ->and($ukrainian->fresh()->entry_group_id)->toBe($group)
        ->and($polish->fresh()->entry_group_id)->toBe($group);
});

test('unlinking refuses a record without translations, one in the trash and an actor without edit', function () {
    $actor = translator();
    $alone = Entry::factory()->create();
    $page = Entry::factory()->create();
    $trashed = Entry::factory()->translationOf($page, 'uk')->create(['deleted_at' => now()]);

    expect(fn () => app(UnlinkTranslation::class)->handle($actor, $alone))->toThrow(ProtectedActionException::class, __('content.link.alone'))
        ->and(fn () => app(UnlinkTranslation::class)->handle($actor, $trashed))->toThrow(ProtectedActionException::class, __('content.entry.trashed'));

    $this->actingAs($actor);
    translationForm($page)->call('confirmUnlink', $trashed->id)->assertNotFound();
    translationForm($page)->call('confirmUnlink', $alone->id)->assertNotFound();

    $this->actingAs(User::factory()->withPermissions(['admin.access', 'pages.view'])->create());
    translationForm($page)->call('confirmUnlink', $page->id)->assertForbidden();
});

test('a form left open does not undo a language change made elsewhere', function () {
    $this->actingAs($actor = translator());
    $page = Entry::factory()->create(['slug' => 'about', 'path' => 'about']);
    $stale = translationForm($page);

    translationForm($page)->call('changeLocale', 'uk')->call('save')->assertHasNoErrors();

    $stale->set('form.title', 'A fixed typo')->call('save')->assertHasNoErrors();

    expect($page->fresh())->locale->toBe('uk')->title->toBe('A fixed typo');
});

test('a new translation is not offered a language its group has in the trash', function () {
    $this->actingAs(translator());
    $page = Entry::factory()->create();
    Entry::factory()->translationOf($page, 'pl')->create(['deleted_at' => now()]);

    Livewire::withQueryParams(['from' => (string) $page->id, 'locale' => 'uk'])
        ->test('pages::admin.content.create', ['type' => 'pages'])
        ->assertDontSee('Polski (PL)')
        ->call('changeLocale', 'pl')
        ->assertReturned(null)
        ->assertSet('locale', 'uk');
});

test('a page can be dated before 1970 and after 2038', function (string $date) {
    $this->actingAs(translator());
    $page = Entry::factory()->create();

    translationForm($page)->set('form.status', 'published')->set('form.published_at', $date.'T10:00')->call('save')->assertHasNoErrors();

    expect($page->fresh()->published_at->format('Y-m-d'))->toBe($date);
})->with(['1965-05-01', '2040-02-03']);

test('a save is refused when its parent left the language or moved under the record meanwhile', function () {
    $paths = app(EntryPaths::class);
    $page = Entry::factory()->create(['slug' => 'team', 'path' => 'team']);
    $parent = Entry::factory()->create(['slug' => 'company', 'path' => 'company']);
    $refused = fn (Closure $save, string $field) => expect($save)
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors())->toHaveKey($field));

    $paths->lockForSave($page, $parent, 'en');

    // The parent took another language after it was validated.
    Entry::query()->whereKey($parent->id)->update(['locale' => 'uk']);
    $refused(fn () => $paths->lockForSave($page, $parent, 'en'), 'parent_id');
    $refused(fn () => $paths->lockForSave(null, $parent, 'en'), 'parent_id');

    // The parent was moved under the record itself.
    Entry::query()->whereKey($parent->id)->update(['locale' => 'en', 'path' => 'team/company']);
    $parent->path = 'team/company';
    $refused(fn () => $paths->lockForSave($page, $parent, 'en'), 'parent_id');

    // The parent went to the trash.
    Entry::query()->whereKey($parent->id)->update(['path' => 'company', 'deleted_at' => now()]);
    $parent->path = 'company';
    $refused(fn () => $paths->lockForSave($page, $parent, 'en'), 'parent_id');

    // The record itself was moved by another request.
    Entry::query()->whereKey($page->id)->update(['path' => 'our-team']);
    $refused(fn () => $paths->lockForSave($page, null, 'en'), 'slug');
});

test('linking decides from the stored records, not from the loaded ones', function () {
    $actor = translator();
    $page = Entry::factory()->create();
    $ukrainian = Entry::factory()->inLocale('uk')->create();

    // Meanwhile the chosen record took the page's own language.
    Entry::query()->whereKey($ukrainian->id)->update(['locale' => 'en']);

    expect(fn () => app(LinkTranslations::class)->handle($actor, $page, $ukrainian))
        ->toThrow(ProtectedActionException::class, __('content.link.overlap'))
        ->and($ukrainian->fresh()->entry_group_id)->not->toBe($page->entry_group_id);
});

test('unlinking copies the shared values as they are stored now', function () {
    $actor = translator();
    $page = Entry::factory()->create();
    $ukrainian = Entry::factory()->translationOf($page, 'uk')->create();
    $ukrainian->load('group');

    // Another translation saved a shared value after this one was loaded.
    $page->group->update(['shared' => ['price' => 25]]);

    app(UnlinkTranslation::class)->handle($actor, $ukrainian);

    expect($ukrainian->fresh()->group->shared)->toBe(['price' => 25]);
});
