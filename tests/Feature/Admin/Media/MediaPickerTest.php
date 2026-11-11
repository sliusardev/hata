<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\Features\SupportTesting\ComponentState;
use Livewire\Features\SupportTesting\RequestBroker;
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

it('opens a folder from its tile, shows its subfolders and only its own files, and leads back by the breadcrumb', function () {
    $this->actingAs(pickerUser());
    $brand = MediaFolder::factory()->create(['name' => 'Brand']);
    $icons = MediaFolder::factory()->create(['name' => 'Icons', 'parent_id' => $brand->id]);
    $deep = MediaFolder::factory()->create(['name' => 'Deep', 'parent_id' => $icons->id]);
    $other = MediaFolder::factory()->create(['name' => 'Other']);
    $root = Media::factory()->image()->create(['name' => 'loose.webp']);
    $inBrand = Media::factory()->image()->inFolder($brand)->create(['name' => 'brand-mark.webp']);
    $inIcons = Media::factory()->image()->inFolder($icons)->create(['name' => 'icon.webp']);

    // At the root: every file, the top-level folders and "No folder" as tiles, no drag and no folder menus.
    $picker = testPicker()->call('openPicker')
        ->assertSeeHtml('data-test="media-subfolder-open-'.$brand->id.'"')
        ->assertSeeHtml('data-test="media-subfolder-open-'.$other->id.'"')
        ->assertSeeHtml('data-test="media-subfolder-open-none"')
        ->assertDontSeeHtml('data-test="media-subfolder-open-'.$icons->id.'"')
        ->assertDontSeeHtml('draggable="true"')
        ->assertDontSeeHtml('data-test="media-subfolder-actions-');
    expect($picker->instance()->subfolders->pluck('id')->all())->toBe([$brand->id, $other->id])
        ->and($picker->instance()->files->modelKeys())->toEqualCanonicalizing([$root->id, $inBrand->id, $inIcons->id]);

    // A tile sets the folder: its subfolders as tiles, only its own files, the breadcrumb leads back.
    $picker->set('folder', (string) $brand->id)
        ->assertSeeHtml('data-test="media-subfolder-open-'.$icons->id.'"')
        ->assertDontSeeHtml('data-test="media-subfolder-open-'.$deep->id.'"')
        ->assertDontSeeHtml('data-test="media-subfolder-open-none"')
        ->assertSeeHtml('data-test="media-breadcrumb-root"');
    expect($picker->instance()->subfolders->pluck('id')->all())->toBe([$icons->id])
        ->and($picker->instance()->files->modelKeys())->toBe([$inBrand->id]);

    $picker->set('folder', (string) $icons->id)
        ->assertSeeHtml('data-test="media-breadcrumb-'.$brand->id.'"');
    expect($picker->instance()->files->modelKeys())->toBe([$inIcons->id]);

    $picker->set('folder', 'none');
    expect($picker->instance()->subfolders)->toBeEmpty()
        ->and($picker->instance()->files->modelKeys())->toBe([$root->id]);

    $picker->set('folder', '')->assertDontSeeHtml('data-test="media-breadcrumb-root"');
    expect($picker->instance()->subfolders->pluck('id')->all())->toBe([$brand->id, $other->id]);
});

it('hides the folder tiles while searching', function () {
    $this->actingAs(pickerUser());
    MediaFolder::factory()->create(['name' => 'Brand']);

    $picker = testPicker()->call('openPicker')->set('search', 'x');

    expect($picker->instance()->subfolders)->toBeEmpty();
});

it('falls back to the root for a folder id that is unknown or not an id', function (string $folder) {
    $this->actingAs(pickerUser());

    testPicker()->call('openPicker')
        ->set('folder', $folder)
        ->assertSet('folder', '');
})->with(['987654', 'abc', '1 or 1=1']);

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
    [$a, $b, $c, $d] = Media::factory()->image()->count(4)->create()->all();

    $picker = testPicker(['multiple' => true, 'value' => []])
        ->call('openPicker')
        ->call('pick', $c->id)
        ->call('pick', $a->id)
        ->call('pick', $b->id)
        ->call('pick', $a->id)
        ->assertSet('picked', [$c->id, $b->id])
        ->call('pick', $a->id)
        ->call('pickAndChoose', $d->id)
        ->assertSet('value', [$c->id, $b->id, $a->id, $d->id]);

    $picker->call('remove', $b->id)->assertSet('value', [$c->id, $a->id, $d->id]);
});

it('keeps a picked file in place when it is double clicked with multiple', function () {
    $this->actingAs(pickerUser());
    [$a, $b, $c] = Media::factory()->image()->count(3)->create()->all();

    // The browser sends the first click of a double click as pick (which takes a picked file out), then pickAndChoose.
    testPicker(['multiple' => true, 'value' => [$a->id, $b->id, $c->id]])
        ->call('openPicker')
        ->call('pick', $b->id)
        ->call('pickAndChoose', $b->id)
        ->assertSet('value', [$a->id, $b->id, $c->id])
        ->assertSet('showModal', false);
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
    // Past the newest id: MySQL keeps its auto-increment across the rolled-back test transactions, so a fixed id may exist.
    $missing = $image->id + 1000;

    testPicker()
        ->call('openPicker')
        ->call('pick', $document->id)
        ->call('pick', $gif->id)
        ->call('pick', $missing)
        ->assertSet('picked', [])
        ->call('pickAndChoose', $document->id)
        ->assertSet('value', null)
        ->set('value', $document->id)
        ->assertSet('value', null)
        ->set('value', $missing)
        ->assertSet('value', null)
        ->set('value', $image->id)
        ->assertSet('value', $image->id);

    testPicker(['multiple' => true, 'value' => []])
        ->set('value', [$document->id, $image->id, $missing, $image->id])
        ->assertSet('value', [$image->id]);
});

it('uploads into the open folder, selects the new file and switches to the library', function () {
    $this->actingAs(pickerUser(['media.upload']));
    $folder = MediaFolder::factory()->create();

    $picker = testPicker()
        ->call('openPicker')
        ->set('folder', (string) $folder->id)
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
        ->assertSet('tab', 'library')
        ->assertSet('folder', (string) $folder->id);
    expect($picker->instance()->files->modelKeys())->toBe([$media->id]);
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
    '_startUpload' => ['_startUpload', ['upload', [['name' => 'a.png', 'size' => 100, 'type' => 'image/png']], false]],
]);

it('keeps a value set from the browser to the mounted files without media.view', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());
    [$mounted, $other] = Media::factory()->image()->count(2)->create()->all();

    testPicker(['value' => $mounted->id])
        ->set('value', $other->id)
        ->assertSet('value', null)
        ->assertDontSee($other->name);

    testPicker(['multiple' => true, 'value' => [$mounted->id]])
        ->set('value', [$other->id, $mounted->id])
        ->assertSet('value', [$mounted->id])
        ->assertDontSee($other->name)
        ->set('value', [])
        ->assertSet('value', []);
});

it('normalizes the value it is mounted with', function (mixed $value, bool $multiple, mixed $expected) {
    $this->actingAs(pickerUser());
    Media::factory()->image()->create(['id' => 7]);

    testPicker(['value' => $value, 'multiple' => $multiple])->assertSet('value', $expected);
})->with([
    'empty string, single' => ['', false, null],
    'digit string, single' => ['7', false, 7],
    'text, single' => ['abc', false, null],
    'list, single' => [[7], false, null],
    'null, multiple' => [null, true, []],
    'empty string, multiple' => ['', true, []],
    'digit strings, multiple' => [['7', 7], true, [7]],
]);

/**
 * A form that holds the picker's value the way a real parent does (`wire:model.live` on the picker).
 */
class MediaPickerTestParent extends Component
{
    public int|string|array|null $logo = null;

    public bool $multiple = false;

    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <livewire:media-picker wire:model.live="logo" accept="image" :multiple="$multiple" />
            </div>
            BLADE;
    }
}

/**
 * The snapshot of the picker rendered inside the parent's test, with the wire:model binding in its memo.
 *
 * @return array<string, mixed>
 */
function childPickerSnapshot(Testable $parent): array
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $parent->html(), $matches);

    return collect($matches[1])
        ->map(fn (string $encoded): array => json_decode(htmlspecialchars_decode($encoded, ENT_QUOTES), true))
        ->firstOrFail(fn (array $snapshot): bool => $snapshot['memo']['name'] === 'media-picker');
}

/**
 * That picker as a testable component of its own (Livewire's test API has no handle on a child), so its next
 * requests carry the binding as they do in the browser.
 *
 * @param  array<string, mixed>  $snapshot
 */
function childPicker(array $snapshot): Testable
{
    $state = new ComponentState(null, null, null, '', $snapshot, []);

    return Closure::bind(fn (): Testable => new Testable(new RequestBroker(app()), $state), null, Testable::class)();
}

it('works with a parent whose field is empty, picks a file and hands its id back to the parent', function (mixed $initial, bool $multiple) {
    $this->actingAs(pickerUser());
    $image = Media::factory()->image()->create();
    $expected = $multiple ? [$image->id] : $image->id;
    Livewire::component('media-picker-test-parent', MediaPickerTestParent::class);

    $parent = Livewire::test('media-picker-test-parent', ['logo' => $initial, 'multiple' => $multiple]);

    // The binding the browser uses to copy the picker's value into the parent's field.
    expect($parent->html())->toContain('wire:model.live="$parent.logo"')->toContain('x-modelable="$wire.value"');

    $snapshot = childPickerSnapshot($parent);
    $mounted = (new ComponentState(null, null, null, '', $snapshot, []))->getSnapshotData();

    expect($mounted['value'])->toBe($multiple ? [] : null);

    $picker = childPicker($snapshot)
        ->call('openPicker')
        ->call('pickAndChoose', $image->id)
        ->assertSet('value', $expected);

    // What x-modelable then writes into the parent's field.
    $parent->set('logo', $picker->get('value'))->assertSet('logo', $expected);
})->with([
    'empty string' => ['', false],
    'null' => [null, false],
    'empty string, multiple' => ['', true],
    'null, multiple' => [null, true],
]);

it('ties its label to the field group', function () {
    $this->actingAs(pickerUser());
    $picker = testPicker();
    $labelId = 'media-picker-label-'.$picker->id();

    $picker->assertSeeHtml('aria-labelledby="'.$labelId.'"')
        ->assertSeeHtml('id="'.$labelId.'"');
});
