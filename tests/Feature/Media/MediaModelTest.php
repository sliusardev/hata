<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a ulid key on create', function () {
    $first = Media::factory()->create();
    $second = Media::factory()->create();

    expect($first->key)->toHaveLength(26)
        ->and($second->key)->toHaveLength(26)
        ->and($first->key)->not->toBe($second->key);
});

it('builds the public url from key and name', function () {
    $media = Media::factory()->create(['name' => 'hero.webp']);

    expect($media->url())->toEndWith('/media/'.$media->key.'/hero.webp')
        ->and($media->thumbUrl())->toBeNull();

    $media->thumb_path = 'media/thumbs/x.webp';

    expect($media->thumbUrl())->toEndWith('/media/'.$media->key.'/hero.webp?thumb=1');
});

it('indexes name, title and every alt for search', function () {
    $media = Media::factory()->create([
        'name' => 'autumn.webp',
        'title' => 'Fall',
        'alt' => ['uk' => 'Осінній Дніпро', 'en' => 'Autumn Dnipro'],
    ]);
    Media::factory()->create(['alt' => []]);

    expect(Media::search('дніпро')->pluck('id')->all())->toBe([$media->id])
        ->and(Media::search('autumn dnipro')->count())->toBe(1)
        ->and($media->altFor('uk'))->toBe('Осінній Дніпро')
        ->and($media->altFor('fr'))->toBe('');
});

it('lists folder descendants at any depth', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);
    $c = MediaFolder::factory()->create(['parent_id' => $b->id]);
    MediaFolder::factory()->create();

    expect($a->descendantIds())->toEqualCanonicalizing([$b->id, $c->id])
        ->and($c->breadcrumb()->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);
});

it('syncs usages', function () {
    $user = User::factory()->create();

    [$one, $two] = Media::factory()->count(2)->create();

    MediaUsage::sync($user, 'avatar', [$one->id, $two->id]);
    expect(MediaUsage::count())->toBe(2);

    MediaUsage::sync($user, 'avatar', [$two->id]);
    expect(MediaUsage::pluck('media_id')->all())->toBe([$two->id]);
});

it('maps media abilities to permissions', function () {
    seedRolesAndPermissions();
    $user = User::factory()->withPermissions(['media.view'])->create();
    $media = Media::factory()->create();

    expect($user->can('viewAny', Media::class))->toBeTrue()
        ->and($user->can('delete', $media))->toBeFalse()
        ->and($user->can('create', Media::class))->toBeFalse();
});

it('reports the uploaded extension only when it differs from the current one', function () {
    $converted = Media::factory()->make(['name' => 'a.webp', 'original_path' => 'media/originals/x.jpg']);
    $jpeg = Media::factory()->make(['name' => 'a.jpg', 'original_path' => 'media/originals/x.jpeg']);
    $same = Media::factory()->make(['name' => 'a.webp', 'original_path' => 'media/originals/x.webp']);
    $none = Media::factory()->make(['name' => 'a.webp', 'original_path' => null]);

    expect($converted->convertedFrom())->toBe('JPG')
        ->and($jpeg->convertedFrom())->toBeNull()
        ->and($same->convertedFrom())->toBeNull()
        ->and($none->convertedFrom())->toBeNull();
});

it('computes the saved percentage', function () {
    $media = Media::factory()->make(['original_size' => 19_293_798, 'size' => 167_936]);

    expect($media->savedPercent())->toBe(99)
        ->and(Media::factory()->make(['original_size' => null])->savedPercent())->toBeNull();
});
