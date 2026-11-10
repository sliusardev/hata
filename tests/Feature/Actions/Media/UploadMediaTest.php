<?php

use App\Actions\Media\OptimizeImage;
use App\Actions\Media\UploadMedia;
use App\Enums\MediaKind;
use App\Exceptions\MediaUploadException;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\Media\MemoryGuard;
use App\Support\Media\OptimizedImage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    seedRolesAndPermissions();
    $this->actor = User::factory()->withPermissions(['admin.access', 'media.upload'])->create();
});

it('uploads and optimizes a photo', function () {
    $file = uploadedImage('Фото відпустки (1).JPG', makeImage('jpg', 3000, 2000));

    $media = app(UploadMedia::class)->handle($this->actor, $file, pickedSize: 18_400_000);

    expect($media->name)->toBe('foto-vidpustky-1.webp')
        ->and($media->original_size)->toBe(18_400_000)
        ->and($media->optimized_with)->toBe('webp:2560:80:exif0')
        ->and($media->optimize_failure)->toBeNull()
        ->and($media->uploaded_extension)->toBe('jpg')
        ->and($media->kind)->toBe(MediaKind::Image)
        ->and($media->mime)->toBe('image/webp')
        ->and($media->width)->toBe(2560)
        ->and($media->uploaded_by)->toBe($this->actor->id)
        ->and($media->disk)->toBe('local');

    Storage::disk('local')->assertExists([$media->path, $media->original_path, $media->thumb_path]);
    expect($media->path)->toStartWith('media/'.now()->format('Y/m').'/')
        ->and($media->original_path)->toStartWith('media/originals/')
        ->and($media->thumb_path)->toBe("media/thumbs/{$media->key}.webp");
});

it('slugs cyrillic names and falls back to file', function () {
    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('.jpg', makeImage()));

    expect($media->name)->toBe('file.webp');
});

it('keeps names unique after conversion', function () {
    $first = app(UploadMedia::class)->handle($this->actor, uploadedImage('a.jpg', makeImage('jpg')));
    $second = app(UploadMedia::class)->handle($this->actor, uploadedImage('a.png', makeImage('png')));

    expect($first->name)->toBe('a.webp')
        ->and($second->name)->toBe('a-2.webp');
});

it('rejects a php file renamed to jpg', function () {
    $file = uploadedImage('x.jpg', '<?php echo "hi";');

    expect(fn () => app(UploadMedia::class)->handle($this->actor, $file))
        ->toThrow(MediaUploadException::class, __('media.errors.unsupported', ['name' => 'x.jpg']));

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('enforces the size limit using the picked size', function () {
    config(['media.limits.image_mb' => 1]);
    $file = uploadedImage('a.jpg', makeImage());

    try {
        app(UploadMedia::class)->handle($this->actor, $file, pickedSize: 2 * 1024 * 1024);
        $this->fail('Expected MediaUploadException.');
    } catch (MediaUploadException $e) {
        expect($e->getMessage())->toBe(__('media.errors.too_large', ['size' => '2.0', 'limit' => '1.0']));
    }

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('stores an image the server cannot process as it is', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(limitBytes: 1024 * 1024));
    $bytes = makeImage('jpg', 2000, 2000);

    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('big.jpg', $bytes));

    expect($media->optimized_with)->toBeNull()
        ->and($media->optimize_failure)->toBe('too_large')
        ->and($media->uploaded_extension)->toBe('jpg')
        ->and($media->name)->toBe('big.jpg')
        ->and($media->thumb_path)->toBeNull()
        ->and(Storage::disk('local')->get($media->path))->toBe($bytes);
});

it('stores documents without processing', function () {
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('Report 2026.pdf', $pdf));

    expect($media->kind)->toBe(MediaKind::Document)
        ->and($media->name)->toBe('report-2026.pdf')
        ->and($media->thumb_path)->toBeNull()
        ->and($media->original_path)->toBeNull()
        ->and($media->optimized_with)->toBeNull()
        ->and(Storage::disk('local')->get($media->path))->toBe($pdf);
});

it('does not keep originals when the setting is off', function () {
    config(['media.optimize.keep_originals' => false]);

    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('a.jpg', makeImage()));

    expect($media->original_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toHaveCount(2);
});

it('puts the file in the given folder', function () {
    $folder = MediaFolder::factory()->create();

    $media = app(UploadMedia::class)->handle($this->actor, uploadedImage('a.jpg', makeImage()), $folder);

    expect($media->folder_id)->toBe($folder->id);
});

it('requires the upload permission', function () {
    $actor = User::factory()->withPermissions(['admin.access', 'media.view'])->create();

    expect(fn () => app(UploadMedia::class)->handle($actor, uploadedImage('a.jpg', makeImage())))
        ->toThrow(AuthorizationException::class);

    try {
        app(UploadMedia::class)->handle($actor, uploadedImage('a.jpg', makeImage()));
    } catch (AuthorizationException $e) {
        expect($e)->not->toBeInstanceOf(MediaUploadException::class);
    }
});

it('deletes written files when the insert fails', function () {
    Media::creating(fn () => throw new RuntimeException('boom'));

    expect(fn () => app(UploadMedia::class)->handle($this->actor, uploadedImage('a.jpg', makeImage())))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(Media::count())->toBe(0);
});

it('rejects heic the server cannot read', function () {
    $bytes = (string) file_get_contents(base_path('tests/fixtures/media/sample.heic'));
    $this->mock(OptimizeImage::class)
        ->shouldReceive('handle')
        ->andReturn(new OptimizedImage($bytes, 'image/heic', 'heic', null, null, optimized: false, failure: 'unreadable'));

    expect(fn () => app(UploadMedia::class)->handle($this->actor, uploadedImage('IMG_1.heic', $bytes)))
        ->toThrow(MediaUploadException::class, __('media.errors.heic_unsupported'));

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('records the extension the user picked when the browser converted the file', function (?string $picked, string $stored) {
    $media = app(UploadMedia::class)->handle(
        $this->actor,
        uploadedImage('photo.jpg', makeImage()),
        pickedSize: 5_000_000,
        pickedExtension: $picked,
    );

    expect($media->uploaded_extension)->toBe($stored);
})->with([
    'heic picked' => ['HEIC', 'heic'],
    'png picked' => ['png', 'png'],
    'jpeg is stored as jpg' => ['jpeg', 'jpg'],
    'not on the whitelist' => ['psd', 'jpg'],
    'junk' => ['../x<script>', 'jpg'],
    'none' => [null, 'jpg'],
]);

it('rejects heic whenever the optimizer could not convert it', function (OptimizedImage $result) {
    $bytes = (string) file_get_contents(base_path('tests/fixtures/media/sample.heic'));
    $this->mock(OptimizeImage::class)->shouldReceive('handle')->andReturn($result);

    expect(fn () => app(UploadMedia::class)->handle($this->actor, uploadedImage('IMG_1.heic', $bytes)))
        ->toThrow(MediaUploadException::class, __('media.errors.heic_unsupported'));

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'too large' => fn () => new OptimizedImage('heic', 'image/heic', 'heic', 640, 480, optimized: false, failure: 'too_large'),
    'stored as heic' => fn () => new OptimizedImage('heic', 'image/heic', 'heic', 640, 480, optimized: true, fingerprint: 'webp:2560:80:exif0'),
    'heif' => fn () => new OptimizedImage('heif', 'image/heif', 'heif', 640, 480, optimized: true, fingerprint: 'webp:2560:80:exif0'),
]);
