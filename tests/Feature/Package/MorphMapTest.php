<?php

use App\Models\User;
use Hata\Actions\Settings\SetLogo;
use Hata\Models\Entry;
use Hata\Models\EntryGroup;
use Hata\Models\Media;
use Hata\Models\MediaUsage;
use Hata\Models\Setting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores short names in media usages', function () {
    $media = Media::factory()->create();
    $entry = Entry::factory()->create();

    MediaUsage::sync($entry, 'body', [$media->id]);
    MediaUsage::sync($entry->group, 'shared.gallery', [$media->id]);
    app(SetLogo::class)->assign('light', $media->id);

    expect(MediaUsage::query()->orderBy('id')->pluck('usable_type')->all())->toBe(['entry', 'entry_group', 'setting'])
        ->and(Relation::getMorphedModel('entry'))->toBe(Entry::class)
        ->and(Relation::getMorphedModel('entry_group'))->toBe(EntryGroup::class)
        ->and(Relation::getMorphedModel('setting'))->toBe(Setting::class)
        ->and(MediaUsage::query()->where('usable_type', 'entry')->first()->usable->is($entry))->toBeTrue();
});

it('does not force the host to map its own models', function () {
    $user = User::factory()->create();
    $usage = MediaUsage::query()->create([
        'media_id' => Media::factory()->create()->id,
        'usable_type' => $user->getMorphClass(),
        'usable_id' => $user->id,
        'field' => 'avatar',
    ]);

    expect(Relation::requiresMorphMap())->toBeFalse()
        ->and($usage->usable_type)->toBe(User::class)
        ->and($usage->fresh()->usable->is($user))->toBeTrue();
});
