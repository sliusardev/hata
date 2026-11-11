<?php

use App\Actions\Media\DeleteMedia;
use App\Actions\Media\MoveMediaToDisk;
use App\Actions\Media\PruneMediaDeletions;
use App\Actions\Media\UploadMedia;
use App\Enums\MoveOutcome;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * Runs the main cloud flows against a real S3-compatible store (e.g. a local MinIO) configured by the AWS_* variables
 * in .env. Skipped unless MEDIA_S3_INTEGRATION=1. Every object goes under a fresh prefix that is removed afterwards.
 */

beforeEach(function () {
    if (getenv('MEDIA_S3_INTEGRATION') !== '1') {
        $this->markTestSkipped('Set MEDIA_S3_INTEGRATION=1 to run against the S3 store configured in .env.');
    }

    Storage::fake('local');
    seedRolesAndPermissions();
    $this->prefix = 'hata-integration-'.Str::lower((string) Str::ulid());
    config([
        'media.disk' => 's3',
        'filesystems.disks.s3.root' => $this->prefix,
        // Local stores often run on a self-signed certificate.
        'filesystems.disks.s3.http' => ['verify' => false],
        'media.optimize.enabled' => true,
        'media.optimize.keep_originals' => true,
    ]);
    Storage::forgetDisk('s3');
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.view', 'media.upload', 'media.edit', 'media.delete'])->create();
});

afterEach(function () {
    if (isset($this->prefix)) {
        // The prefix is the disk root, so this removes every object the test created.
        Storage::disk('s3')->deleteDirectory('');
        expect(Storage::disk('s3')->allFiles())->toBe([]);
    }
});

it('uploads, serves directly, moves to local and back, and deletes on a real store', function () {
    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('photo.jpg', makeImage('jpg', 1200, 800)));
    $cloud = Storage::disk('s3');

    expect($media->disk)->toBe('s3')
        ->and($cloud->exists($media->path))->toBeTrue()
        ->and($cloud->exists((string) $media->thumb_path))->toBeTrue();

    // The direct URL serves the stored object when the bucket is public; a private bucket answers 403, and then the
    // same object is fetched by a signed URL instead (the write options must have reached the store either way).
    expect($media->url())->toContain($this->prefix)->toEndWith('?v=1');
    $response = Http::withoutVerifying()->get($media->url());
    if ($response->status() === 403) {
        $response = Http::withoutVerifying()->get($cloud->temporaryUrl($media->path, now()->addMinutes(5)));
    }
    expect($response->status())->toBe(200)
        ->and($response->body())->toBe($cloud->get($media->path))
        ->and($response->header('Content-Type'))->toBe('image/webp')
        ->and($response->header('Cache-Control'))->toBe('public, max-age=604800');

    $mover = app(MoveMediaToDisk::class);
    $cloudPaths = [$media->path, $media->thumb_path, $media->original_path];

    expect($mover->moveOne($media, 'local'))->toBe(MoveOutcome::Moved);
    $media->refresh();
    expect($media->disk)->toBe('local')
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue()
        ->and($cloud->exists($cloudPaths[0]))->toBeTrue();

    expect($mover->moveOne($media, 's3'))->toBe(MoveOutcome::Moved);
    $media->refresh();
    expect($media->disk)->toBe('s3')
        ->and($media->path)->toContain('-v3')
        ->and($cloud->exists($media->path))->toBeTrue()
        ->and($cloud->exists((string) $media->original_path))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    app(DeleteMedia::class)->handle($this->actor, $media);
    expect($cloud->exists($media->path))->toBeFalse();

    // The objects of version 1 waited for their deferred deletion.
    $this->travel(8)->days();
    app(PruneMediaDeletions::class)->handle();

    foreach ($cloudPaths as $path) {
        expect($cloud->exists((string) $path))->toBeFalse();
    }
    expect(Media::query()->count())->toBe(0);
});

it('signs a stable url a real store accepts, and stores svg as an inert download', function () {
    $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    $document = app(UploadMedia::class)->handle($this->actor, UploadedFile::fake()->createWithContent('terms.pdf', $pdf));
    $this->actingAs($this->actor);

    $first = $this->get($document->url())->assertStatus(302)->headers->get('Location');
    $second = $this->get($document->url())->assertStatus(302)->headers->get('Location');
    expect($second)->toBe($first);

    $response = Http::withoutVerifying()->get((string) $first);
    expect($response->status())->toBe(200)
        ->and($response->body())->toBe($pdf)
        ->and($response->header('Content-Disposition'))->toContain('terms.pdf');

    $svg = app(UploadMedia::class)->handle($this->actor, UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'));
    $stored = Http::withoutVerifying()->get(Storage::disk('s3')->temporaryUrl($svg->path, now()->addMinutes(5)));
    expect($stored->header('Content-Type'))->toBe('application/octet-stream')
        ->and($stored->header('Content-Disposition'))->toBe('attachment');
});
