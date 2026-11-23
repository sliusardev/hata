<?php

use App\Models\User;
use Hata\Actions\Media\ReplaceMediaFile;
use Hata\Actions\Media\RestoreOriginal;
use Hata\Exceptions\MediaChangedException;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', 'media.upload'])->create();
    $this->actingAs($this->actor);
});

/**
 * A stored image row, optionally with a kept original.
 */
function replaceTarget(?string $original = null): Media
{
    $media = Media::factory()->create(['name' => 'old.jpg', 'mime' => 'image/jpeg']);
    $media->forceFill(['original_path' => $original === null ? null : "media/originals/{$media->key}.jpg"])->save();

    Storage::disk('local')->put($media->path, makeImage('jpg', 400, 300));

    if ($original !== null) {
        Storage::disk('local')->put((string) $media->original_path, $original);
    }

    return $media;
}

function replacementFile(string $name, string $bytes): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

it('replaces the open file after confirmation', function () {
    $media = replaceTarget();
    MediaUsage::query()->create(['media_id' => $media->id, 'usable_type' => User::class, 'usable_id' => 1, 'field' => 'cover']);
    $url = $media->url();

    $component = Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('replacement', replacementFile('Fresh photo.jpg', makeImage('jpg', 1000, 700)))
        ->set('replacementPickedSize', 5_000_000)
        ->set('replacementPickedExtension', 'jpg')
        ->call('confirmReplace')
        ->assertSet('showReplaceModal', true)
        ->assertSet('replacingId', $media->id)
        ->assertSee(__('media.replace.heading', ['name' => 'old.jpg']))
        ->call('replaceConfirmed')
        ->assertSet('showReplaceModal', false)
        ->assertSet('replacingId', null)
        ->assertSet('replacement', null)
        ->assertDispatched('toast-show');

    $media->refresh();

    expect($component->effects['returns'][0])->toBe(['ok' => true])
        ->and($media->name)->toBe('fresh-photo.webp')
        ->and($media->version)->toBe(2)
        ->and($media->original_size)->toBe(5_000_000)
        ->and($media->url())->not->toBe($url);

    $component->assertSee('fresh-photo.webp')->assertSee($media->url());
});

it('refuses a replacement of another kind with a toast and keeps the file', function () {
    $media = replaceTarget();
    $before = Storage::disk('local')->get($media->path);

    $component = Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('replacement', replacementFile('doc.pdf', "%PDF-1.4\n%%EOF"))
        ->call('confirmReplace')
        ->call('replaceConfirmed')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger')
        ->assertSet('replacement', null)
        ->assertSet('replacingId', null);

    expect($component->effects['returns'][0]['ok'])->toBeFalse()
        ->and($component->effects['returns'][0]['error'])->toBe(__('media.errors.other_kind', ['kind' => __('media.errors.kinds.image')]))
        ->and($media->refresh()->name)->toBe('old.jpg')
        ->and($media->version)->toBe(1)
        ->and(Storage::disk('local')->get($media->path))->toBe($before);
});

it('restores the original after confirmation', function () {
    $media = replaceTarget(makeImage('jpg', 900, 600));

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('data-test="media-details-restore"')
        ->call('confirmRestore')
        ->assertSet('showRestoreModal', true)
        ->assertSet('restoringId', $media->id)
        ->call('restoreConfirmed')
        ->assertSet('showRestoreModal', false)
        ->assertSet('restoringId', null)
        ->assertDispatched('toast-show');

    expect($media->refresh()->version)->toBe(2);
});

it('shows a danger toast when the original file is gone', function () {
    $media = replaceTarget(makeImage('jpg', 900, 600));
    Storage::disk('local')->delete((string) $media->original_path);

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->call('confirmRestore')
        ->call('restoreConfirmed')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    expect($media->refresh()->version)->toBe(1);
});

it('reports and shows a danger toast when restoring fails unexpectedly', function () {
    $media = replaceTarget(makeImage('jpg', 900, 600));
    $this->mock(RestoreOriginal::class)->shouldReceive('handle')->andThrow(new RuntimeException('disk error'));
    Exceptions::fake();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->call('confirmRestore')
        ->call('restoreConfirmed')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger');

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'disk error');
});

it('hides restore without an original', function () {
    $media = replaceTarget();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('data-test="media-details-replace"')
        ->assertDontSeeHtml('data-test="media-details-restore"')
        ->call('confirmRestore')
        ->assertSet('showRestoreModal', false)
        ->assertSet('restoringId', null);
});

it('hides replace, restore and crop without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view'])->create());
    $media = replaceTarget(makeImage('jpg', 900, 600));

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('data-test="media-details"')
        ->assertDontSeeHtml('data-test="media-details-replace"')
        ->assertDontSeeHtml('data-test="media-details-restore"')
        ->assertDontSeeHtml('data-test="media-details-crop"')
        ->call('confirmReplace')
        ->assertSet('showReplaceModal', false)
        ->call('confirmRestore')
        ->assertSet('showRestoreModal', false);
});

it('refuses a replacement upload without media.edit', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create());
    $media = replaceTarget();

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->call('_startUpload', 'replacement', [['name' => 'x.jpg', 'size' => 10, 'type' => 'image/jpeg']], false)
        ->assertForbidden();
});

it('forgets the pending replace or restore on cancel', function () {
    $media = replaceTarget(makeImage('jpg', 900, 600));

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('replacement', replacementFile('x.jpg', makeImage()))
        ->call('confirmReplace')
        ->call('cancelPending')
        ->assertSet('showReplaceModal', false)
        ->assertSet('replacingId', null)
        ->assertSet('replacement', null)
        ->call('replaceConfirmed')
        ->call('confirmRestore')
        ->call('cancelPending')
        ->assertSet('showRestoreModal', false)
        ->assertSet('restoringId', null)
        ->call('restoreConfirmed');

    expect($media->refresh()->version)->toBe(1);
});

it('says the file was changed meanwhile when a replace or restore loses the race', function () {
    $media = replaceTarget(makeImage('jpg', 900, 600));
    $this->mock(ReplaceMediaFile::class)->shouldReceive('handle')->andThrow(MediaChangedException::changed());
    $this->mock(RestoreOriginal::class)->shouldReceive('handle')->andThrow(MediaChangedException::changed());
    $danger = fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
        && ($params['slots']['text'] ?? null) === __('media.errors.changed_meanwhile');

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->set('replacement', replacementFile('Fresh photo.jpg', makeImage('jpg', 1000, 700)))
        ->call('confirmReplace')
        ->call('replaceConfirmed')
        ->assertReturned(['ok' => false, 'error' => __('media.errors.changed_meanwhile')])
        ->assertDispatched('toast-show', $danger);

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->call('confirmRestore')
        ->call('restoreConfirmed')
        ->assertDispatched('toast-show', $danger);
});
