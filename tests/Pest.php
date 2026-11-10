<?php

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

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
 * Create the registered permissions and the system roles, as the DatabaseSeeder does.
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
    $path = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, null, null, true);
}
