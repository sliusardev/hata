<?php

use Hata\Database\Seeders\RoleSeeder;
use Hata\Models\Media;
use Hata\Support\Media\DirectServing;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\CustomAdminPathTestCase;
use Tests\Fixtures\RecordingDisk;
use Tests\Fixtures\SeoRoutesOffTestCase;
use Tests\TestCase;

require_once __DIR__.'/fixtures/RecordingDisk.php';
require_once __DIR__.'/fixtures/ProductsType.php';
require_once __DIR__.'/fixtures/CustomAdminPathTestCase.php';
require_once __DIR__.'/fixtures/ShopSettingsSection.php';
require_once __DIR__.'/fixtures/SeoRoutesOffTestCase.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// An application whose admin path is set before Hata's routes load: a test file cannot change the case it runs on.
pest()->extend(CustomAdminPathTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('AdminPath');

// The sitemap and robots.txt routes are switched off in the config before the routes load, for the same reason.
pest()->extend(SeoRoutesOffTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('SeoRoutesOff');

// Install tests build the schema themselves (as a host's `migrate` would), so they run without RefreshDatabase.
pest()->extend(TestCase::class)
    ->in('Install');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create the registered permissions and the system roles, as HataSeeder does.
 */
function seedRolesAndPermissions(): void
{
    test()->artisan('permissions:sync');
    test()->seed(RoleSeeder::class);
}

/**
 * Bytes of a generated test image (GD). $format is one of jpg, png, webp, gif.
 */
function makeImage(string $format = 'jpg', int $width = 800, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, imagecolorallocate($image, 200, 60, 60));

    ob_start();
    match ($format) {
        'png' => imagepng($image),
        'webp' => imagewebp($image),
        'gif' => imagegif($image),
        default => imagejpeg($image),
    };

    return (string) ob_get_clean();
}

/**
 * Insert a minimal EXIF (APP1) segment right after the SOI marker of a JPEG.
 */
function withExif(string $jpeg): string
{
    $payload = "Exif\0\0"."II*\0\x08\0\0\0\0\0";

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/**
 * Bytes of a hand-built 2-frame animated GIF (two graphic control extension blocks).
 */
function animatedGif(): string
{
    $frame = "\x21\xF9\x04\x00\x0A\x00\x00\x00"
        ."\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00"
        ."\x02\x02\x44\x01\x00";

    return "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF"
        ."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00"
        .$frame.$frame."\x3B";
}

/**
 * An uploaded file with the given name and content. Backed by a real temp file, so
 * getMimeType() sniffs the content (UploadedFile::fake() reports the MIME from the extension).
 */
function uploadedImage(string $name, string $bytes): UploadedFile
{
    static $registered = false;
    static $paths = [];

    if (! $registered) {
        register_shutdown_function(function () use (&$paths): void {
            array_map(fn (string $path) => @unlink($path), $paths);
        });
        $registered = true;
    }

    $path = tempnam(sys_get_temp_dir(), 'upl');
    $paths[] = $path;
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A media row with its three files written to the faked disk.
 *
 * @param  array<string, mixed>  $attributes
 */
function mediaWithFiles(array $attributes = []): Media
{
    $media = Media::factory()->create($attributes + [
        'original_path' => 'media/originals/x'.uniqid().'.jpg',
        'thumb_path' => 'media/thumbs/x'.uniqid().'.webp',
    ]);

    foreach ([$media->path, $media->original_path, $media->thumb_path] as $path) {
        Storage::disk('local')->put($path, 'data');
    }

    return $media;
}

/**
 * Fake a cloud disk: the disk keeps a non-local driver in the config (so MediaDisks::isCloud() is true) and its
 * objects live in a faked local folder that records the options each object was written with.
 */
function fakeCloudDisk(string $disk = 's3'): RecordingDisk
{
    config(["filesystems.disks.{$disk}.driver" => 's3']);

    /** @var FilesystemAdapter $fake */
    $fake = Storage::fake($disk);
    $recording = new RecordingDisk($fake->getDriver(), $fake->getAdapter(), $fake->getConfig());
    Storage::set($disk, $recording);

    return $recording;
}

/**
 * Make the site's media disk the public one: raster images go to the faked `public` disk, originals, SVG and
 * documents to the faked `local` one.
 */
function usePublicMediaDisk(bool $direct = false): void
{
    Storage::fake('public');
    Storage::fake('local');
    config(['hata.media.disk' => 'public']);

    if ($direct) {
        DirectServing::fake();
    }
}
