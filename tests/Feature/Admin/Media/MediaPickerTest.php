<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
});

/**
 * @param  list<string>  $extra
 */
function pickerUser(array $extra = []): User
{
    return User::factory()->withPermissions(['admin.access', 'media.view', ...$extra])->create();
}

/**
 * @param  array<string, mixed>  $props
 */
function testPicker(array $props = []): Testable
{
    return Livewire::test('media-picker', [
        'accept' => 'image',
        'extensions' => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
        'label' => 'Logo',
        ...$props,
    ]);
}

it('lists only files of the accepted kind and marks other extensions as unusable', function () {
    $this->actingAs(pickerUser());
    $photo = Media::factory()->image()->create(['name' => 'photo.webp']);
    $gif = Media::factory()->image()->create(['name' => 'spinner.gif', 'mime' => 'image/gif']);
    Media::factory()->document()->create(['name' => 'terms.pdf']);

    testPicker()
        ->call('openPicker')
        ->assertSet('showModal', true)
        ->assertSee('photo.webp')
        ->assertSee('spinner.gif')
        ->assertDontSee('terms.pdf')
        ->assertSeeHtml('data-test="media-picker-tile-'.$photo->id.'"')
        ->assertSeeHtml('data-test="media-picker-tile-disabled-'.$gif->id.'"')
        ->assertSee(__('media.picker.not_allowed'));
});

it('narrows the library by search, month and folder and loads 30 files at a time', function () {
    $this->actingAs(pickerUser());
    $folder = MediaFolder::factory()->create(['name' => 'Brand']);
    $inFolder = Media::factory()->image()->inFolder($folder)->create(['name' => 'brand-mark.webp']);
    Media::factory()->image()->count(31)->create();
    $old = Media::factory()->image()->create(['name' => 'old-shot.webp', 'created_at' => now()->subYear()]);

    $picker = testPicker()->call('openPicker');
    expect($picker->instance()->files)->toHaveCount(30);

    $picker->call('loadMore');
    expect($picker->instance()->files)->toHaveCount(33);

    $picker->set('search', 'brand-mark');
    expect($picker->instance()->files->modelKeys())->toBe([$inFolder->id]);

    $picker->set('search', '')->set('folder', (string) $folder->id);
    expect($picker->instance()->files->modelKeys())->toBe([$inFolder->id]);

    $picker->set('folder', '')->set('month', now()->subYear()->tz(config('admin.timezone'))->format('Y-m'));
    expect($picker->instance()->files->modelKeys())->toBe([$old->id]);
});

it('selects a file with a click and sets the value with Select', function () {
    $this->actingAs(pickerUser());
    [$first, $second] = Media::factory()->image()->count(2)->create()->all();

    testPicker()
        ->call('openPicker')
        ->call('pick', $first->id)
        ->call('pick', $second->id)
        ->assertSet('picked', [$second->id])
        ->assertSet('focusedId', $second->id)
        ->assertSet('value', null)
        ->call('choose')
        ->assertSet('value', $second->id)
        ->assertSet('showModal', false)
        ->assertSee($second->name);
});

it('selects and closes on a double click', function () {
    $this->actingAs(pickerUser());
    $image = Media::factory()->image()->create();

    testPicker()
        ->call('openPicker')
        ->call('pickAndChoose', $image->id)
        ->assertSet('value', $image->id)
        ->assertSet('showModal', false);
});

it('keeps the pick order and toggles files with multiple', function () {
    $this->actingAs(pickerUser());
    [$a, $b, $c] = Media::factory()->image()->count(3)->create()->all();

    $picker = testPicker(['multiple' => true, 'value' => []])
        ->call('openPicker')
        ->call('pick', $c->id)
        ->call('pick', $a->id)
        ->call('pick', $b->id)
        ->call('pick', $a->id)
        ->assertSet('picked', [$c->id, $b->id])
        ->call('pickAndChoose', $a->id)
        ->assertSet('value', [$c->id, $b->id, $a->id]);

    $picker->call('remove', $b->id)->assertSet('value', [$c->id, $a->id]);
});

it('removes the current choice', function () {
    $this->actingAs(pickerUser());
    $image = Media::factory()->image()->create();

    testPicker(['value' => $image->id])
        ->assertSee($image->name)
        ->call('remove')
        ->assertSet('value', null);
});

it('ignores ids that are missing or not allowed', function () {
    $this->actingAs(pickerUser());
    $document = Media::factory()->document()->create();
    $gif = Media::factory()->image()->create(['name' => 'spinner.gif', 'mime' => 'image/gif']);
    $image = Media::factory()->image()->create();

    testPicker()
        ->call('openPicker')
        ->call('pick', $document->id)
        ->call('pick', $gif->id)
        ->call('pick', 999)
        ->assertSet('picked', [])
        ->call('pickAndChoose', $document->id)
        ->assertSet('value', null)
        ->set('value', $document->id)
        ->assertSet('value', null)
        ->set('value', 999)
        ->assertSet('value', null)
        ->set('value', $image->id)
        ->assertSet('value', $image->id);

    testPicker(['multiple' => true, 'value' => []])
        ->set('value', [$document->id, $image->id, 999, $image->id])
        ->assertSet('value', [$image->id]);
});

it('uploads into the chosen folder, selects the new file and switches to the library', function () {
    $this->actingAs(pickerUser(['media.upload']));
    $folder = MediaFolder::factory()->create();

    $picker = testPicker()
        ->call('openPicker')
        ->assertSee(__('media.picker.tabs.upload'))
        ->set('tab', 'upload')
        ->set('upload', UploadedFile::fake()->createWithContent('Logo.png', makeImage('png', 300, 120)))
        ->set('uploadFolderId', $folder->id)
        ->call('storeUpload');

    $media = Media::query()->sole();

    expect($picker->effects['returns'][0])->toMatchArray(['ok' => true, 'id' => $media->id])
        ->and($media->folder_id)->toBe($folder->id);

    $picker->assertSet('picked', [$media->id])
        ->assertSet('focusedId', $media->id)
        ->assertSet('tab', 'library');
});

it('refuses an upload outside the accepted kind or extensions', function (string $name, Closure $bytes, array $props) {
    $this->actingAs(pickerUser(['media.upload']));

    $picker = testPicker($props)
        ->call('openPicker')
        ->set('upload', UploadedFile::fake()->createWithContent($name, $bytes()))
        ->call('storeUpload');

    expect($picker->effects['returns'][0])->toBe(['ok' => false, 'error' => __('media.picker.not_allowed')])
        ->and(Media::query()->count())->toBe(0);

    $picker->assertSet('picked', []);
})->with([
    'animated gif for the logo extensions' => ['spinner.gif', fn () => animatedGif(), []],
    'document for an image field' => ['terms.txt', fn () => 'Plain text terms', ['extensions' => null]],
]);

it('hides the upload tab and refuses uploads without media.upload', function () {
    $this->actingAs(pickerUser());

    testPicker()
        ->call('openPicker')
        ->assertDontSee(__('media.picker.tabs.upload'))
        ->call('storeUpload')
        ->assertForbidden();

    expect(Media::query()->count())->toBe(0);
});

it('saves the primary alt text through the library and keeps other languages', function () {
    $this->actingAs(pickerUser(['media.edit']));
    $image = Media::factory()->image()->create(['alt' => ['en' => 'Old', 'uk' => 'Старий']]);

    testPicker()
        ->call('openPicker')
        ->call('pick', $image->id)
        ->assertSet('alt', 'Old')
        ->assertSee(__('media.picker.alt_note'))
        ->set('alt', 'Company logo')
        ->call('saveAlt')
        ->assertHasNoErrors();

    expect($image->fresh()->alt)->toBe(['en' => 'Company logo', 'uk' => 'Старий']);
});

it('refuses alt edits without media.edit', function () {
    $this->actingAs(pickerUser());
    $image = Media::factory()->image()->create(['alt' => ['en' => 'Old']]);

    testPicker()
        ->call('openPicker')
        ->call('pick', $image->id)
        ->set('alt', 'Changed')
        ->call('saveAlt')
        ->assertForbidden();

    expect($image->fresh()->alt)->toBe(['en' => 'Old']);
});

it('shows only the current choice and Remove without media.view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.upload', 'media.edit'])->create());
    $image = Media::factory()->image()->create();

    testPicker(['value' => $image->id])
        ->assertSee($image->name)
        ->assertSee(__('media.picker.needs_access'))
        ->assertDontSeeHtml('data-test="media-picker-open"')
        ->call('remove')
        ->assertSet('value', null);
});

it('refuses every library method without media.view', function (string $method, array $arguments) {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.upload', 'media.edit'])->create());
    $image = Media::factory()->image()->create();
    $arguments = array_map(fn (mixed $argument): mixed => $argument === 'id' ? $image->id : $argument, $arguments);

    testPicker()
        ->call($method, ...$arguments)
        ->assertForbidden();
})->with([
    'openPicker' => ['openPicker', []],
    'pick' => ['pick', ['id']],
    'pickAndChoose' => ['pickAndChoose', ['id']],
    'choose' => ['choose', []],
    'loadMore' => ['loadMore', []],
    'saveAlt' => ['saveAlt', []],
    'storeUpload' => ['storeUpload', []],
]);
