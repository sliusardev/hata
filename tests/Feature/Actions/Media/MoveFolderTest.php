<?php

use App\Models\User;
use Hata\Actions\Media\MoveFolder;
use Hata\Models\Media;
use Hata\Models\MediaFolder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.edit'])->create();
});

it('moves a folder into another folder and to the top level, keeping its files', function () {
    $a = MediaFolder::factory()->create(['name' => 'A']);
    $b = MediaFolder::factory()->create(['name' => 'B']);
    $media = Media::factory()->inFolder($b)->create();

    app(MoveFolder::class)->handle($this->actor, $b, $a);

    expect($b->refresh()->parent_id)->toBe($a->id)
        ->and($media->refresh()->folder_id)->toBe($b->id);

    app(MoveFolder::class)->handle($this->actor, $b, null);

    expect($b->refresh()->parent_id)->toBeNull();
});

it('treats a move to the current parent as a no-op', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);

    $result = app(MoveFolder::class)->handle($this->actor, $b, $a);

    expect($result->parent_id)->toBe($a->id);
});

it('refuses moving a folder into itself or a descendant', function () {
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create(['parent_id' => $a->id]);
    $c = MediaFolder::factory()->create(['parent_id' => $b->id]);

    foreach ([$a, $b, $c] as $target) {
        try {
            app(MoveFolder::class)->handle($this->actor, $a, $target);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('folder');
        }
    }

    expect($a->refresh()->parent_id)->toBeNull()
        ->and($b->refresh()->parent_id)->toBe($a->id);
});

it('refuses a move that would duplicate a sibling name', function () {
    $target = MediaFolder::factory()->create();
    MediaFolder::factory()->create(['parent_id' => $target->id, 'name' => 'Photos']);
    $folder = MediaFolder::factory()->create(['name' => 'Photos']);

    try {
        app(MoveFolder::class)->handle($this->actor, $folder, $target);
        $this->fail('Expected a ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('name');
    }

    expect($folder->refresh()->parent_id)->toBeNull();
});

it('requires media.edit', function () {
    $viewer = User::factory()->withPermissions(['admin.access', 'media.view'])->create();
    $a = MediaFolder::factory()->create();
    $b = MediaFolder::factory()->create();

    expect(fn () => app(MoveFolder::class)->handle($viewer, $a, $b))->toThrow(AuthorizationException::class);
    expect($a->refresh()->parent_id)->toBeNull();
});

it('finds descendants of a folder that sits in a cycle and lets the cycle be broken', function () {
    $a = MediaFolder::factory()->create(['name' => 'A']);
    $b = MediaFolder::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
    MediaFolder::query()->whereKey($a->id)->update(['parent_id' => $b->id]);

    expect($a->refresh()->descendantIds())->toBe([$b->id])
        ->and($b->refresh()->descendantIds())->toBe([$a->id]);

    app(MoveFolder::class)->handle($this->actor, $a->refresh(), null);

    expect($a->refresh()->parent_id)->toBeNull()
        ->and($b->refresh()->parent_id)->toBe($a->id);
});

it('turns a unique name violation raised while saving into the name error', function () {
    $target = MediaFolder::factory()->create();
    MediaFolder::factory()->create(['parent_id' => $target->id, 'name' => 'Same']);
    $folder = MediaFolder::factory()->create(['name' => 'Same']);

    MediaFolder::saving(function (MediaFolder $saving) use ($folder): void {
        if ($saving->is($folder)) {
            throw new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('duplicate'));
        }
    });

    expect(fn () => app(MoveFolder::class)->handle($this->actor, $folder, $target))
        ->toThrow(ValidationException::class);
});
