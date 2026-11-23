<?php

use App\Models\User;
use Hata\Actions\Media\CropMedia;
use Hata\Exceptions\MediaChangedException;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Hata\Support\Media\MemoryGuard;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\RecordingDisk;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', 'media.upload'])->create();
    $this->actingAs($this->actor);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function cropPageMedia(string $current, ?string $original = null, array $attributes = []): Media
{
    $media = Media::factory()->create($attributes);
    $media->forceFill(['original_path' => $original === null ? null : "media/originals/{$media->key}.jpg"])->save();

    Storage::disk('local')->put($media->path, $current);

    if ($original !== null) {
        Storage::disk('local')->put((string) $media->original_path, $original);
    }

    return $media;
}

it('opens the crop page for an editable image', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600), attributes: ['name' => 'hero.webp']);

    $this->get(route('admin.media.crop', $media))
        ->assertOk()
        ->assertSee('hero.webp')
        ->assertSee(route('admin.media.source', $media), escape: false)
        ->assertSee(__('hata::media.crop.apply'));
});

it('gives the ratio buttons a click expression the browser can run', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600));

    $this->get(route('admin.media.crop', $media))
        ->assertOk()
        ->assertSee('x-on:click="setRatio(\'free\')"', escape: false)
        ->assertSee('x-on:click="setRatio(\'16:9\')"', escape: false)
        ->assertDontSee('@js(', escape: false);
});

it('is forbidden without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $media = cropPageMedia(makeImage('webp'));

    $this->get(route('admin.media.crop', $media))->assertForbidden();
});

it('is not found for svg, animated gif and documents', function (string $kind) {
    $media = match ($kind) {
        'svg' => cropPageMedia('<svg xmlns="http://www.w3.org/2000/svg"/>', attributes: ['mime' => 'image/svg+xml', 'name' => 'a.svg']),
        'gif' => cropPageMedia(animatedGif(), attributes: ['mime' => 'image/gif', 'name' => 'a.gif', 'animated' => true]),
        default => tap(Media::factory()->document()->create(), fn (Media $media) => Storage::disk('local')->put($media->path, '%PDF-1.4')),
    };

    $this->get(route('admin.media.crop', $media))->assertNotFound();
})->with(['svg', 'gif', 'document']);

it('seeds the cropper from stored edits', function () {
    $media = cropPageMedia(makeImage('webp', 1500, 1000), makeImage('jpg', 3000, 2000));
    $media->forceFill(['edits' => [
        'rotate' => 90,
        'flip_h' => true,
        'flip_v' => false,
        'crop' => ['x' => 500, 'y' => 1500, 'w' => 1000, 'h' => 1500],
        'size' => ['w' => 800, 'h' => 600],
    ]])->save();

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->assertSet('edits.rotate', 90)
        ->assertSet('edits.flip_h', true)
        ->assertSet('edits.flip_v', false)
        ->assertSet('edits.crop', ['x' => 0.25, 'y' => 0.5, 'w' => 0.5, 'h' => 0.5])
        ->assertSet('width', 800)
        ->assertSet('height', 600);
});

it('starts fresh when no original is kept', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->assertSet('edits.rotate', 0)
        ->assertSet('edits.crop', null)
        ->assertSet('ratio', 'free')
        ->assertSet('width', null);
});

it('applies the crop after confirmation and redirects to the library', function () {
    $folder = MediaFolder::factory()->create();
    $media = cropPageMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3000, 2000), ['folder_id' => $folder->id, 'name' => 'hero.webp']);
    MediaUsage::query()->create(['media_id' => $media->id, 'usable_type' => User::class, 'usable_id' => 1, 'field' => 'cover']);
    MediaUsage::query()->create(['media_id' => $media->id, 'usable_type' => User::class, 'usable_id' => 2, 'field' => 'cover']);

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('edits.crop', ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('confirmApply')
        ->assertSet('showApplyModal', true)
        ->assertSee(__('hata::media.crop.confirm.heading', ['name' => 'hero.webp']))
        ->assertSee(trans_choice('hata::media.crop.confirm.body', 2))
        ->call('apply')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.media.index', ['folder' => $folder->id]));

    $media->refresh();
    $image = Image::fromBytes((string) Storage::disk('local')->get($media->path));

    expect($media->version)->toBe(2)
        ->and($media->edits['crop'])->toEqual(['x' => 0, 'y' => 0, 'w' => 1500, 'h' => 1000])
        ->and([$image->width(), $image->height()])->toBe([1500, 1000])
        ->and(session('toast.text'))->toBe(__('hata::media.crop.messages.applied'));
});

it('shows an invalid crop as an error and stays on the page', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('edits.crop', ['x' => 0.8, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('apply')
        ->assertHasErrors('crop')
        ->assertNoRedirect();

    expect($media->fresh()->version)->toBe(1);
});

it('saves a copy and leaves the source unchanged', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600), attributes: ['name' => 'hero.webp']);
    $before = Storage::disk('local')->get($media->path);

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('edits.crop', ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('saveCopy')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.media.index', ['folder' => 'none']));

    $copy = Media::query()->whereKeyNot($media->id)->sole();

    expect($media->fresh()->version)->toBe(1)
        ->and(Storage::disk('local')->get($media->path))->toBe($before)
        ->and([$copy->width, $copy->height])->toBe([400, 300])
        ->and(session('toast.text'))->toBe(__('hata::media.crop.messages.copied', ['name' => $copy->name]));
});

it('hides save as copy without media.upload', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit'])->create());
    $media = cropPageMedia(makeImage('webp', 800, 600));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->assertDontSee(__('hata::media.crop.save_copy'))
        ->set('edits.crop', ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('saveCopy')
        ->assertDispatched('toast-show', fn (string $name, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertNoRedirect();

    expect(Media::query()->count())->toBe(1);
});

it('shows the result size', function () {
    $media = cropPageMedia(makeImage('webp', 2560, 1920), makeImage('jpg', 4000, 3000));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->assertSee('2560 × 1920')
        ->set('edits.crop', ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5])
        ->assertSee('2000 × 1500')
        ->set('width', 1000)
        ->assertSee('1000 × 750')
        ->set('width', '')
        ->assertSet('width', null)
        ->assertSee('2000 × 1500');
});

it('shows the size after the max-side scale, as the applied file will have it', function (?array $size, string $expected, bool $hint) {
    $media = cropPageMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3800, 2500));

    $page = Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('width', $size[0] ?? null)
        ->set('height', $size[1] ?? null)
        ->assertSee($expected);

    $hint ? $page->assertSeeHtml('data-test="media-crop-max-side-hint"') : $page->assertDontSeeHtml('data-test="media-crop-max-side-hint"');

    $page->call('apply')->assertHasNoErrors();

    $image = Image::fromBytes((string) Storage::disk('local')->get($media->refresh()->path));

    expect("{$image->width()} × {$image->height()}")->toBe($expected);
})->with([
    'default crop of a 3800 px original' => [null, '2560 × 1684', false],
    'exact size above the cap' => [[3000, 2000], '2560 × 1684', true],
    'exact size below the cap' => [[1000, 1000], '1000 × 658', false],
]);

it('shows the uncapped size when max-side scaling is off', function () {
    config(['media.optimize.max_side' => 0]);
    $media = cropPageMedia(makeImage('webp', 2560, 1707), makeImage('jpg', 3800, 2500));

    Livewire::test('hata::admin.media.crop', ['media' => $media])->assertSee('3800 × 2500');
});

it('sets the social size with the social ratio', function () {
    $media = cropPageMedia(makeImage('webp', 2560, 1920));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('ratio', 'social')
        ->assertSet('width', 1200)
        ->assertSet('height', 630)
        ->set('ratio', '1:1')
        ->assertSet('width', null)
        ->assertSet('height', null)
        ->set('ratio', 'bogus')
        ->assertSet('ratio', 'free');
});

it('links to the crop page from the details panel for editable images only', function () {
    $image = cropPageMedia(makeImage('webp', 800, 600));
    $svg = cropPageMedia('<svg xmlns="http://www.w3.org/2000/svg"/>', attributes: ['mime' => 'image/svg+xml', 'name' => 'a.svg']);

    Livewire::test('hata::admin.media.index')
        ->call('select', $image->id)
        ->assertSee(route('admin.media.crop', $image), escape: false)
        ->call('select', $svg->id)
        ->assertDontSee(route('admin.media.crop', $svg), escape: false);
});

it('explains when the image is too large to edit on this server', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(1024));
    $media = cropPageMedia(makeImage('webp', 800, 600));

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->assertSee(__('hata::media.errors.too_large_to_edit'))
        ->assertDontSee(route('admin.media.source', $media), escape: false)
        ->assertDontSee(__('hata::media.crop.apply'));
});

it('says the file was changed meanwhile when the crop loses the race', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600));
    $this->mock(CropMedia::class)->shouldReceive('handle')->andThrow(MediaChangedException::changed());

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('edits.crop', ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('apply')
        ->assertHasErrors(['crop' => __('hata::media.errors.changed_meanwhile')])
        ->assertNoRedirect();
});

it('shows a storage error and keeps the file when the disk does not store the crop', function () {
    $media = cropPageMedia(makeImage('webp', 800, 600));
    $fake = Storage::disk('local');
    $failing = new RecordingDisk($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
    $failing->failWrites = true;
    Storage::set('local', $failing);

    Livewire::test('hata::admin.media.crop', ['media' => $media])
        ->set('edits.crop', ['x' => 0, 'y' => 0, 'w' => 0.5, 'h' => 0.5])
        ->call('apply')
        ->assertHasErrors(['crop' => __('hata::media.errors.storage_failed')])
        ->assertNoRedirect();

    expect($media->fresh()->version)->toBe(1);
});
