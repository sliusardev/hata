<?php

use App\Models\User;
use Hata\Enums\MediaKind;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

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

    expect($media->url())->toEndWith('/media/'.$media->key.'/hero.webp?v=1')
        ->and($media->thumbUrl())->toBeNull();

    $media->thumb_path = 'media/thumbs/x.webp';

    expect($media->thumbUrl())->toEndWith('/media/'.$media->key.'/hero.webp?thumb=1&v=1');
});

it('puts the version in the file and thumbnail urls', function () {
    $media = Media::factory()->create(['name' => 'hero.webp', 'version' => 3, 'thumb_path' => 'media/thumbs/x.webp']);

    expect($media->url())->toEndWith('?v=3')
        ->and($media->thumbUrl())->toContain('thumb=1', 'v=3');
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
        ->and($media->altFor('fr', fallback: false))->toBe('');
});

it('falls back to the primary language for an empty alt translation', function () {
    config(['hata.content.interface_default' => 'uk', 'hata.admin.interface_locales' => ['en' => 'English', 'uk' => 'Українська', 'pl' => 'Polski']]);
    $media = Media::factory()->make(['alt' => ['uk' => 'Каша', 'en' => '']]);

    expect(Media::primaryAltLocale())->toBe('uk')
        ->and($media->altFor('pl'))->toBe('Каша')
        ->and($media->altFor('en'))->toBe('Каша')
        ->and($media->altFor('en', fallback: false))->toBe('')
        ->and($media->altFor('uk'))->toBe('Каша');

    config(['hata.content.interface_default' => 'fr']);

    expect(Media::primaryAltLocale())->toBe('en');
});

it('treats a whitespace-only alt as empty for the fallback', function () {
    config(['hata.content.interface_default' => 'uk', 'hata.admin.interface_locales' => ['en' => 'English', 'uk' => 'Українська']]);
    $media = Media::factory()->make(['alt' => ['uk' => 'Каша', 'en' => "  \t"]]);

    expect($media->altFor('en'))->toBe('Каша')
        ->and($media->altFor('en', fallback: false))->toBe('');

    $media->alt = ['uk' => '   ', 'en' => 'Porridge'];

    expect($media->altFor('pl'))->toBe('');
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
    $legacy = ['uploaded_extension' => null];
    $converted = Media::factory()->make(['name' => 'a.webp', 'original_path' => 'media/originals/x.jpg', ...$legacy]);
    $jpeg = Media::factory()->make(['name' => 'a.jpg', 'original_path' => 'media/originals/x.jpeg', ...$legacy]);
    $same = Media::factory()->make(['name' => 'a.webp', 'original_path' => 'media/originals/x.webp', ...$legacy]);
    $none = Media::factory()->make(['name' => 'a.webp', 'original_path' => null, ...$legacy]);
    $stored = Media::factory()->make(['name' => 'a.webp', 'original_path' => null, 'uploaded_extension' => 'png']);
    $storedWins = Media::factory()->make(['name' => 'a.webp', 'original_path' => 'media/originals/x.jpg', 'uploaded_extension' => 'png']);

    expect($converted->convertedFrom())->toBe('JPG')
        ->and($jpeg->convertedFrom())->toBeNull()
        ->and($same->convertedFrom())->toBeNull()
        ->and($none->convertedFrom())->toBeNull()
        ->and($stored->convertedFrom())->toBe('PNG')
        ->and($storedWins->convertedFrom())->toBe('PNG');
});

it('computes the saved percentage', function () {
    $media = Media::factory()->make(['original_size' => 19_293_798, 'size' => 167_936]);

    expect($media->savedPercent())->toBe(99)
        ->and(Media::factory()->make(['original_size' => null])->savedPercent())->toBeNull();
});

it('knows which files can be edited', function () {
    Storage::fake('local');
    $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00\x21\xF9\x04\x00\x00\x00\x00\x00\x21\xF9\x04\x00\x00\x00\x00\x00;";
    Storage::disk('local')->put('a.jpg', makeImage('jpg', 20, 20));
    Storage::disk('local')->put('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    Storage::disk('local')->put('a.gif', $gif);
    Storage::disk('local')->put('a.pdf', '%PDF-1.4');

    $make = fn (string $path, string $mime, MediaKind $kind, bool $animated = false) => Media::factory()->create(['disk' => 'local', 'path' => $path, 'mime' => $mime, 'kind' => $kind, 'animated' => $animated]);

    expect($make('a.jpg', 'image/jpeg', MediaKind::Image)->isEditable())->toBeTrue()
        ->and($make('a.svg', 'image/svg+xml', MediaKind::Image)->isEditable())->toBeFalse()
        ->and($make('a.gif', 'image/gif', MediaKind::Image, animated: true)->isEditable())->toBeFalse()
        ->and($make('a.jpg', 'image/jpeg', MediaKind::Image, animated: true)->isEditable())->toBeFalse()
        ->and($make('a.pdf', 'application/pdf', MediaKind::Document)->isEditable())->toBeFalse()
        ->and($make('missing.jpg', 'image/jpeg', MediaKind::Image)->isEditable())->toBeFalse();
});
