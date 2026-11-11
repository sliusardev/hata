<?php

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\MediaUsage;
use App\Models\User;
use App\Support\Media\MediaFilters;
use App\Support\Media\StoreUploadedMedia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

uses(RefreshDatabase::class);

function filteredNames(MediaFilters $filters): array
{
    return $filters->apply(Media::query())->orderBy('name')->pluck('name')->all();
}

it('searches by name', function () {
    Media::factory()->create(['name' => 'cat.webp']);
    Media::factory()->create(['name' => 'dog.webp']);

    expect(filteredNames(new MediaFilters(search: 'cat')))->toBe(['cat.webp']);
});

it('filters by kind and ignores an unknown type', function () {
    Media::factory()->create(['name' => 'a.webp', 'kind' => 'image']);
    Media::factory()->create(['name' => 'b.pdf', 'kind' => 'document']);

    expect(filteredNames(new MediaFilters(type: 'document')))->toBe(['b.pdf'])
        ->and(filteredNames(new MediaFilters(type: 'all')))->toBe(['a.webp', 'b.pdf']);
});

it('reads the month in the site timezone as a utc range', function () {
    config(['admin.timezone' => 'Europe/Kyiv']);

    [$start, $end] = MediaFilters::monthRange('2026-03');

    expect($start->toIso8601String())->toBe('2026-02-28T22:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-03-31T20:59:59+00:00')
        ->and(MediaFilters::monthRange('2026-13'))->toBeNull()
        ->and(MediaFilters::monthRange(''))->toBeNull();

    Media::factory()->create(['name' => 'in.webp', 'created_at' => CarbonImmutable::parse('2026-02-28 22:00:00', 'UTC')]);
    Media::factory()->create(['name' => 'before.webp', 'created_at' => CarbonImmutable::parse('2026-02-28 21:59:59', 'UTC')]);
    Media::factory()->create(['name' => 'out.webp', 'created_at' => CarbonImmutable::parse('2026-03-31 21:00:00', 'UTC')]);

    expect(filteredNames(new MediaFilters(month: '2026-03')))->toBe(['in.webp']);
});

it('filters by folder, no folder and unused', function () {
    $folder = MediaFolder::factory()->create();
    Media::factory()->create(['name' => 'in.webp', 'folder_id' => $folder->id]);
    $loose = Media::factory()->create(['name' => 'loose.webp', 'folder_id' => null]);

    expect(filteredNames(new MediaFilters(folder: $folder)))->toBe(['in.webp'])
        ->and(filteredNames(new MediaFilters(noFolder: true)))->toBe(['loose.webp']);

    MediaUsage::query()->create([
        'media_id' => $loose->id, 'usable_type' => 'x', 'usable_id' => 1, 'field' => 'f',
    ]);

    expect(filteredNames(new MediaFilters(unused: true)))->toBe(['in.webp']);
});

it('filters by the extension of the current name', function () {
    Media::factory()->create(['name' => 'a.WEBP']);
    Media::factory()->create(['name' => 'b.pdf']);
    Media::factory()->create(['name' => 'webp']);

    expect(filteredNames(new MediaFilters(extensions: ['webp'])))->toBe(['a.WEBP'])
        ->and(filteredNames(new MediaFilters(extensions: [])))->toBe([])
        ->and(filteredNames(new MediaFilters(extensions: null)))->toBe(['a.WEBP', 'b.pdf', 'webp']);
});

function pickedUpload(): array
{
    Storage::fake('local');
    seedRolesAndPermissions();
    $actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload'])->create();

    Storage::fake('tmp-for-tests');
    $path = 'abc-meta'.base64_encode('pic.jpg').'-mimeType=image_jpeg-.jpg';
    Storage::disk('tmp-for-tests')->put('livewire-tmp/'.$path, makeImage('jpg', 200, 100));

    return [$actor, new TemporaryUploadedFile($path, 'tmp-for-tests')];
}

it('refuses a file outside the allowed extensions and stores nothing', function () {
    [$actor, $upload] = pickedUpload();

    $result = app(StoreUploadedMedia::class)->handle($actor, $upload, null, null, null, ['pdf', 'zip']);

    expect($result)->toBe(['ok' => false, 'error' => __('media.picker.not_allowed')])
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('stores a file whose stored extension is allowed', function () {
    [$actor, $upload] = pickedUpload();

    $result = app(StoreUploadedMedia::class)->handle($actor, $upload, null, null, null, ['webp']);

    expect($result['ok'])->toBeTrue()
        ->and(Media::query()->sole()->name)->toEndWith('.webp');
});

it('still returns the refusal when removing the refused file fails', function () {
    [$actor, $upload] = pickedUpload();
    Media::deleting(fn () => throw new RuntimeException('delete failed'));
    $this->withoutExceptionHandling();

    $result = app(StoreUploadedMedia::class)->handle($actor, $upload, null, null, null, ['pdf']);

    expect($result)->toBe(['ok' => false, 'error' => __('media.picker.not_allowed')]);
});
