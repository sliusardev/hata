<?php

use App\Models\User;
use Hata\Actions\Media\CreateFolder;
use Hata\Actions\Media\DeleteFolder;
use Hata\Actions\Media\RenameFolder;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Hata\Models\MediaUsage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit', 'media.delete'])->create();
});

it('creates nested folders and refuses a duplicate sibling name', function () {
    $create = app(CreateFolder::class);

    $a = $create->handle($this->actor, '  Photos ', null);
    $b = $create->handle($this->actor, 'Summer', $a);
    $other = $create->handle($this->actor, 'Other', null);

    expect($a->name)->toBe('Photos')
        ->and($b->parent_id)->toBe($a->id)
        ->and($create->handle($this->actor, 'Summer', $other)->parent_id)->toBe($other->id);

    expect(fn () => $create->handle($this->actor, 'Summer', $a))->toThrow(ValidationException::class);
    expect(fn () => $create->handle($this->actor, '   ', $a))->toThrow(ValidationException::class);
    expect(fn () => $create->handle($this->actor, str_repeat('a', 101), $a))->toThrow(ValidationException::class);
});

it('refuses a duplicate folder name at the root', function () {
    app(CreateFolder::class)->handle($this->actor, 'Photos', null);

    expect(fn () => app(CreateFolder::class)->handle($this->actor, 'Photos', null))->toThrow(ValidationException::class);
});

it('rejects a folder name made only of non-breaking spaces', function () {
    expect(fn () => app(CreateFolder::class)->handle($this->actor, "\u{00A0}\u{00A0}", null))->toThrow(ValidationException::class);
});

it('deletes a folder with many files in a bounded number of queries', function () {
    $folder = MediaFolder::factory()->create();
    Media::factory()->count(30)->inFolder($folder)->create();

    DB::enableQueryLog();
    app(DeleteFolder::class)->handle($this->actor, $folder);
    $deletes = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'delete'))->count();
    DB::disableQueryLog();

    expect(Media::query()->count())->toBe(0)
        ->and($deletes)->toBeLessThan(5);
});

it('renames a folder', function () {
    $folder = MediaFolder::factory()->create(['name' => 'Old']);
    MediaFolder::factory()->create(['name' => 'Taken']);

    expect(app(RenameFolder::class)->handle($this->actor, $folder, ' New ')->name)->toBe('New');
    expect(app(RenameFolder::class)->handle($this->actor, $folder, 'New')->name)->toBe('New');
    expect(fn () => app(RenameFolder::class)->handle($this->actor, $folder, 'Taken'))->toThrow(ValidationException::class);
});

it('deletes nested folders and their files', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);
    $c = MediaFolder::factory()->create(['parent_id' => $b->id]);
    $paths = [];

    foreach ([[$a, 1], [$b, 2], [$c, 3]] as [$folder, $count]) {
        for ($i = 0; $i < $count; $i++) {
            $media = mediaWithFiles(['folder_id' => $folder->id]);
            array_push($paths, $media->path, $media->original_path, $media->thumb_path);
        }
    }

    $result = app(DeleteFolder::class)->handle($this->actor, $a);

    expect($result)->toBe(['folders' => 3, 'files' => 6])
        ->and(MediaFolder::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($paths);
});

it('summarizes what a folder deletion removes', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);
    $used = Media::factory()->inFolder($a)->create();
    Media::factory()->inFolder($b)->count(2)->create();
    Media::factory()->create();
    MediaUsage::query()->create(['media_id' => $used->id, 'usable_type' => 'post', 'usable_id' => 1, 'field' => 'cover']);

    expect($a->deletionSummary())->toBe(['folders' => 2, 'files' => 3, 'used' => 1]);
});

it('requires both media.edit and media.delete to delete a folder', function (array $permissions) {
    $actor = User::factory()->withPermissions(['admin.access', ...$permissions])->create();
    $folder = MediaFolder::factory()->create();

    expect(fn () => app(DeleteFolder::class)->handle($actor, $folder))->toThrow(AuthorizationException::class);
    expect(MediaFolder::query()->count())->toBe(1);
})->with([[['media.edit']], [['media.delete']]]);
