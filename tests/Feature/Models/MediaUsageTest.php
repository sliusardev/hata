<?php

use App\Actions\Media\DeleteFolder;
use App\Actions\Media\DeleteMedia;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Fixtures\MediaUsingModel;

require_once __DIR__.'/../../fixtures/MediaUsingModel.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    seedRolesAndPermissions();
    Schema::create('media_using_models', function ($table) {
        $table->id();
        $table->timestamps();
    });
    MediaUsingModel::$forgotten = [];
});

function usageOf(Media $media, $usable, string $field = 'cover'): MediaUsage
{
    return MediaUsage::query()->create([
        'media_id' => $media->id,
        'usable_type' => $usable->getMorphClass(),
        'usable_id' => $usable->getKey(),
        'field' => $field,
    ]);
}

it('delegates label and url to a usable that implements UsesMedia', function () {
    $usable = MediaUsingModel::query()->create();
    $usage = usageOf(Media::factory()->create(), $usable, 'logo');
    $viewer = User::factory()->create();

    expect($usage->label())->toBe('Fixture '.$usable->id.' / logo')
        ->and($usage->url($viewer))->toBe('https://example.test/fixtures/'.$usable->id)
        ->and($usage->url(null))->toBeNull();
});

it('falls back to "Type #id" for a plain model and for a missing row', function () {
    $media = Media::factory()->create();
    $user = User::factory()->create();

    $plain = usageOf($media, $user);
    $missing = usageOf($media, new MediaUsingModel(['id' => 999]), 'gone');

    expect($plain->label())->toBe(__('media.details.usage_item', ['type' => 'User', 'id' => $user->id]))
        ->and($plain->url($user))->toBeNull()
        ->and($missing->label())->toBe(__('media.details.usage_item', ['type' => 'MediaUsingModel', 'id' => 999]))
        ->and($missing->url($user))->toBeNull();
});

it('forgets every usage of the given media on usables that implement UsesMedia', function () {
    $usable = MediaUsingModel::query()->create();
    [$one, $two, $other] = Media::factory()->count(3)->create();
    usageOf($one, $usable, 'a');
    usageOf($two, $usable, 'b');
    usageOf($other, $usable, 'c');
    usageOf($one, User::factory()->create());

    MediaUsage::forgetAll([$one, $two]);

    expect(MediaUsingModel::$forgotten)->toEqualCanonicalizing([
        [$usable->id, 'a', $one->id],
        [$usable->id, 'b', $two->id],
    ]);
});

it('forgets usages when a file is deleted', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.delete'])->create();
    $usable = MediaUsingModel::query()->create();
    $media = Media::factory()->create();
    usageOf($media, $usable, 'logo_light');

    app(DeleteMedia::class)->handle($actor, $media);

    expect(MediaUsingModel::$forgotten)->toBe([[$usable->id, 'logo_light', $media->id]]);
});

it('forgets usages of files inside subfolders when a folder is deleted', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.edit', 'media.delete'])->create();
    $parent = MediaFolder::factory()->create();
    $child = MediaFolder::factory()->create(['parent_id' => $parent->id]);
    $media = Media::factory()->inFolder($child)->create();
    $usable = MediaUsingModel::query()->create();
    usageOf($media, $usable, 'logo_dark');

    app(DeleteFolder::class)->handle($actor, $parent);

    expect(MediaUsingModel::$forgotten)->toBe([[$usable->id, 'logo_dark', $media->id]]);
});

it('shows the usage label and link in the details panel and the delete dialog', function () {
    $user = User::factory()->withPermissions(['admin.access', 'media.view', 'media.delete'])->create();
    $this->actingAs($user);
    $usable = MediaUsingModel::query()->create();
    $media = mediaWithFiles();
    usageOf($media, $usable, 'logo');
    $label = 'Fixture '.$usable->id.' / logo';
    $href = 'https://example.test/fixtures/'.$usable->id;

    Livewire::test('pages::admin.media.index')
        ->call('select', $media->id)
        ->assertSeeHtml('href="'.$href.'"')
        ->assertSee($label)
        ->call('confirmDelete', $media->id)
        ->assertSee($label);
});
