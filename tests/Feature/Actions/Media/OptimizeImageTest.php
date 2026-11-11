<?php

use App\Actions\Media\OptimizeImage;
use App\Support\Media\MemoryGuard;
use Illuminate\Image\Transformations\Orient;
use Illuminate\Support\Facades\Image;
use Intervention\Image\Interfaces\ImageInterface;

dataset('drivers', ['gd', 'imagick']);

beforeEach(function () {
    config(['media.optimize' => [
        'enabled' => true,
        'max_side' => 2560,
        'quality' => 80,
        'format' => 'webp',
        'strip_exif' => true,
        'client_resize' => true,
        'keep_originals' => true,
    ]]);
});

function useImageDriver(string $driver): void
{
    if ($driver === 'imagick' && ! extension_loaded('imagick')) {
        test()->markTestSkipped('The imagick extension is not loaded.');
    }

    config(['images.default' => $driver]);
}

it('scales a large jpeg down to the max side and converts it to webp', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg');
    $size = getimagesizefromstring($result->contents);

    expect($result->mime)->toBe('image/webp')
        ->and($result->extension)->toBe('webp')
        ->and($result->width)->toBe(2560)
        ->and($result->height)->toBeGreaterThanOrEqual(1706)->toBeLessThanOrEqual(1707)
        ->and($size['mime'])->toBe('image/webp')
        ->and($size[0])->toBe(2560)
        ->and($result->optimized)->toBeTrue()
        ->and($result->fingerprint)->toBe('webp:2560:80:exif0')
        ->and($result->failure)->toBeNull();
})->with('drivers');

it('never upscales', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 400, 300), 'image/jpeg');
    $size = getimagesizefromstring($result->contents);

    expect([$result->width, $result->height])->toBe([400, 300])
        ->and([$size[0], $size[1]])->toBe([400, 300]);
})->with('drivers');

it('keeps the input when the result is not smaller', function (string $driver) {
    useImageDriver($driver);
    $gif = makeImage('gif', 1, 1);

    $result = app(OptimizeImage::class)->handle($gif, 'image/gif');

    expect($result->contents)->toBe($gif)
        ->and($result->mime)->toBe('image/gif')
        ->and($result->extension)->toBe('gif')
        ->and($result->optimized)->toBeTrue()
        ->and($result->fingerprint)->toBe('webp:2560:80:exif0');
})->with('drivers');

it('strips exif', function (string $driver) {
    useImageDriver($driver);
    $jpeg = withExif(makeImage('jpg', 1200, 800));

    foreach (['webp', 'original'] as $format) {
        config(['media.optimize.format' => $format]);

        $result = app(OptimizeImage::class)->handle($jpeg, 'image/jpeg');

        expect($result->optimized)->toBeTrue()
            ->and($result->contents)->not->toBe($jpeg)
            ->and($result->contents)->not->toContain('Exif');
    }
})->with('drivers');

it('keeps the original format when asked', function (string $driver) {
    useImageDriver($driver);
    config(['media.optimize.format' => 'original']);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg');

    expect($result->mime)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg')
        ->and(getimagesizefromstring($result->contents)['mime'])->toBe('image/jpeg')
        ->and($result->width)->toBe(2560)
        ->and($result->fingerprint)->toBe('original:2560:80:exif0');
})->with('drivers');

it('leaves svg and animated gif untouched', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
    $gif = animatedGif();

    $svgResult = app(OptimizeImage::class)->handle($svg, 'image/svg+xml');
    $gifResult = app(OptimizeImage::class)->handle($gif, 'image/gif');

    expect($svgResult->optimized)->toBeFalse()
        ->and($svgResult->failure)->toBeNull()
        ->and($svgResult->contents)->toBe($svg)
        ->and($svgResult->fingerprint)->toBeNull()
        ->and($svgResult->extension)->toBe('svg')
        ->and($gifResult->optimized)->toBeFalse()
        ->and($gifResult->failure)->toBeNull()
        ->and($gifResult->contents)->toBe($gif)
        ->and($gifResult->mime)->toBe('image/gif')
        ->and($gifResult->extension)->toBe('gif');
});

it('leaves animated webp and apng untouched', function () {
    $webp = 'RIFF'.pack('V', 4 + 18 + 14).'WEBP'
        .'VP8X'.pack('V', 10)."\x02\x00\x00\x00"."\x00\x00\x00"."\x00\x00\x00"
        .'ANIM'.pack('V', 6)."\x00\x00\x00\x00\x00\x00";
    $png = makeImage('png', 4, 4);
    $actl = pack('NN', 2, 0);
    $apng = substr($png, 0, 33).pack('N', 8).'acTL'.$actl.pack('N', crc32('acTL'.$actl)).substr($png, 33);

    $webpResult = app(OptimizeImage::class)->handle($webp, 'image/webp');
    $apngResult = app(OptimizeImage::class)->handle($apng, 'image/png');

    expect($webpResult->optimized)->toBeFalse()
        ->and($webpResult->failure)->toBeNull()
        ->and($webpResult->contents)->toBe($webp)
        ->and($webpResult->extension)->toBe('webp')
        ->and($apngResult->optimized)->toBeFalse()
        ->and($apngResult->failure)->toBeNull()
        ->and($apngResult->contents)->toBe($apng)
        ->and($apngResult->extension)->toBe('png');
});

it('converts heic to webp on imagick', function () {
    if (! extension_loaded('imagick') || Imagick::queryFormats('HEIC') === []) {
        $this->markTestSkipped('Imagick without HEIC support.');
    }

    config(['images.default' => 'imagick']);
    $heic = (string) file_get_contents(base_path('tests/fixtures/media/sample.heic'));

    $result = app(OptimizeImage::class)->handle($heic, 'image/heic');
    $thumbnail = getimagesizefromstring((string) app(OptimizeImage::class)->thumbnail($heic));

    expect($result->mime)->toBe('image/webp')
        ->and($result->extension)->toBe('webp')
        ->and([$result->width, $result->height])->toBe([640, 480])
        ->and($result->optimized)->toBeTrue()
        ->and($result->failure)->toBeNull()
        ->and($thumbnail['mime'])->toBe('image/webp')
        ->and([$thumbnail[0], $thumbnail[1]])->toBe([400, 300]);
});

it('reports heic unreadable on gd', function () {
    config(['images.default' => 'gd']);
    $heic = (string) file_get_contents(base_path('tests/fixtures/media/sample.heic'));

    $result = app(OptimizeImage::class)->handle($heic, 'image/heic');

    expect($result->failure)->toBe('unreadable')
        ->and($result->optimized)->toBeFalse()
        ->and($result->contents)->toBe($heic)
        ->and($result->extension)->toBe('heic')
        ->and(app(OptimizeImage::class)->thumbnail($heic))->toBeNull();
});

it('returns the scaled image even when it is larger than the input', function (string $driver) {
    useImageDriver($driver);
    config(['media.optimize.max_side' => 500, 'media.optimize.quality' => 100]);
    mt_srand(1);
    $noise = imagecreatetruecolor(600, 400);
    for ($x = 0; $x < 600; $x++) {
        for ($y = 0; $y < 400; $y++) {
            imagesetpixel($noise, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }
    ob_start();
    imagejpeg($noise);
    $jpeg = (string) ob_get_clean();

    $result = app(OptimizeImage::class)->handle($jpeg, 'image/jpeg');

    expect(strlen($result->contents))->toBeGreaterThan(strlen($jpeg))
        ->and($result->mime)->toBe('image/webp')
        ->and($result->width)->toBe(500)
        ->and($result->fingerprint)->toBe('webp:500:100:exif0');
})->with('drivers');

it('leaves images untouched when optimization is disabled', function () {
    config(['media.optimize.enabled' => false]);
    $jpeg = makeImage('jpg', 3000, 2000);

    $result = app(OptimizeImage::class)->handle($jpeg, 'image/jpeg');

    expect($result->optimized)->toBeFalse()
        ->and($result->failure)->toBeNull()
        ->and($result->contents)->toBe($jpeg)
        ->and([$result->width, $result->height])->toBe([3000, 2000]);
});

it('refuses images that would not fit in memory', function () {
    app()->instance(MemoryGuard::class, new MemoryGuard(limitBytes: 1024 * 1024));
    $jpeg = makeImage('jpg', 2000, 2000);

    $result = app(OptimizeImage::class)->handle($jpeg, 'image/jpeg');

    expect($result->failure)->toBe('too_large')
        ->and($result->optimized)->toBeFalse()
        ->and($result->contents)->toBe($jpeg)
        ->and(app(OptimizeImage::class)->thumbnail($jpeg))->toBeNull();
});

it('reports unreadable images', function () {
    $result = app(OptimizeImage::class)->handle("\xFF\xD8garbage", 'image/jpeg');

    expect($result->failure)->toBe('unreadable')
        ->and($result->optimized)->toBeFalse()
        ->and($result->contents)->toBe("\xFF\xD8garbage")
        ->and(app(OptimizeImage::class)->thumbnail("\xFF\xD8garbage"))->toBeNull();
});

it('makes a 400px webp thumbnail', function (string $driver) {
    useImageDriver($driver);

    $thumbnail = app(OptimizeImage::class)->thumbnail(makeImage('jpg', 3000, 2000));
    $size = getimagesizefromstring((string) $thumbnail);

    expect($size['mime'])->toBe('image/webp')
        ->and($size[0])->toBe(400)
        ->and($size[1])->toBeGreaterThanOrEqual(266)->toBeLessThanOrEqual(267);
})->with('drivers');

it('returns a thumbnail with the optimized image', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg');
    $size = getimagesizefromstring((string) $result->thumbnail);

    expect($result->thumbnail)->not->toBeNull()
        ->and($result->animated)->toBeFalse()
        ->and($size['mime'])->toBe('image/webp')
        ->and($size[0])->toBe(400)
        ->and($size[1])->toBeGreaterThanOrEqual(266)->toBeLessThanOrEqual(267);
})->with('drivers');

it('returns a thumbnail and the animated flag for an animated gif', function () {
    $result = app(OptimizeImage::class)->handle(animatedGif(), 'image/gif');

    expect($result->animated)->toBeTrue()
        ->and($result->thumbnail)->not->toBeNull()
        ->and($result->contents)->toBe(animatedGif());
});

/**
 * Count how many times the image pipeline decodes a source (every decode runs the Orient step first) on the GD driver.
 */
function countDecodes(): object
{
    useImageDriver('gd');
    $counter = new stdClass;
    $counter->count = 0;
    Image::transformUsing('gd', Orient::class, function (ImageInterface $image) use ($counter): ImageInterface {
        $counter->count++;

        return $image->orient();
    });

    return $counter;
}

it('decodes the image once and takes the thumbnail from that decode', function () {
    $decodes = countDecodes();

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg');

    expect($result->thumbnail)->not->toBeNull()
        ->and($decodes->count)->toBe(1);
});

it('orients the thumbnail like the image', function (string $driver) {
    useImageDriver($driver);
    // 1200 x 600 pixels with EXIF orientation 6 (rotate 90 degrees clockwise): displayed 600 x 1200.
    $ifd = "II*\0\x08\0\0\0\x01\0\x12\x01\x03\0\x01\0\0\0\x06\0\0\0\0\0\0\0";
    $jpeg = makeImage('jpg', 1200, 600);
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($ifd) + 8)."Exif\0\0".$ifd.substr($jpeg, 2);

    $result = app(OptimizeImage::class)->handle($jpeg, 'image/jpeg');
    $thumbnail = getimagesizefromstring((string) $result->thumbnail);

    expect([$result->width, $result->height])->toBe([600, 1200])
        ->and([$thumbnail[0], $thumbnail[1]])->toBe([200, 400]);
})->with('drivers');

it('makes the thumbnail from the edited image', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 800, 600), 'image/jpeg', ['crop' => ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 100]]);
    $thumbnail = getimagesizefromstring((string) $result->thumbnail);

    expect([$result->width, $result->height])->toBe([400, 100])
        ->and([$thumbnail[0], $thumbnail[1]])->toBe([400, 100]);
})->with('drivers');

it('falls back to a thumbnail from the result when the capture would not fit in memory', function () {
    $decodes = countDecodes();
    // The guard says yes to the main decode, no to the capture's copy, and yes to the fallback.
    app()->instance(MemoryGuard::class, new class extends MemoryGuard
    {
        private int $calls = 0;

        public function canDecode(int $width, int $height): bool
        {
            return ++$this->calls !== 2;
        }
    });

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 3000, 2000), 'image/jpeg');
    $size = getimagesizefromstring((string) $result->thumbnail);

    expect($size[0])->toBe(400)
        ->and($decodes->count)->toBe(2);
});

it('builds the fingerprint from the settings', function () {
    config(['media.optimize.max_side' => null, 'media.optimize.quality' => 70, 'media.optimize.strip_exif' => false]);

    expect(app(OptimizeImage::class)->fingerprint())->toBe('webp:none:70:exif1');
});

it('lets the memory guard pass everything when memory is unlimited', function () {
    expect((new MemoryGuard(limitBytes: -1))->canDecode(20000, 20000))->toBeTrue()
        ->and((new MemoryGuard(limitBytes: 1024 * 1024))->canDecode(2000, 2000))->toBeFalse();
});

/**
 * Skip unless the local Imagick can decode the HEIC fixture, and switch the driver to it.
 */
function useHeicDecodingImagick(): string
{
    $heic = (string) file_get_contents(base_path('tests/fixtures/media/sample.heic'));

    try {
        if (! extension_loaded('imagick') || ! (new Imagick)->readImageBlob($heic)) {
            test()->markTestSkipped('Imagick without HEIC decoding.');
        }
    } catch (ImagickException) {
        test()->markTestSkipped('Imagick without HEIC decoding.');
    }

    config(['images.default' => 'imagick']);

    return $heic;
}

it('converts heic to jpeg when the format setting keeps the original', function () {
    $heic = useHeicDecodingImagick();
    config(['media.optimize.format' => 'original']);

    $result = app(OptimizeImage::class)->handle($heic, 'image/heic');

    expect($result->mime)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg')
        ->and($result->failure)->toBeNull()
        ->and(getimagesizefromstring($result->contents)['mime'])->toBe('image/jpeg');
});

it('converts heic to webp even when optimization is disabled', function () {
    $heic = useHeicDecodingImagick();
    config(['media.optimize.enabled' => false, 'media.optimize.max_side' => 320]);

    $result = app(OptimizeImage::class)->handle($heic, 'image/heic');

    expect($result->mime)->toBe('image/webp')
        ->and($result->extension)->toBe('webp')
        ->and($result->failure)->toBeNull()
        ->and([$result->width, $result->height])->toBe([640, 480]);
});

function redCornerPng(): string
{
    $image = imagecreatetruecolor(4, 2);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagesetpixel($image, 0, 0, imagecolorallocate($image, 255, 0, 0));

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('rotates clockwise', function (string $driver) {
    useImageDriver($driver);
    config(['media.optimize.enabled' => false]);

    $result = app(OptimizeImage::class)->handle(redCornerPng(), 'image/png', ['rotate' => 90]);
    $image = imagecreatefromstring($result->contents);
    $topRight = imagecolorsforindex($image, imagecolorat($image, 1, 0));

    expect($result->width)->toBe(2)->and($result->height)->toBe(4)
        ->and($topRight['red'])->toBeGreaterThan(200)
        ->and($topRight['green'])->toBeLessThan(60);
})->with('drivers');

it('crops in the rotated frame', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 800, 600), 'image/jpeg', [
        'rotate' => 90,
        'crop' => ['x' => 0, 'y' => 0, 'w' => 300, 'h' => 400],
    ]);

    expect($result->width)->toBe(300)->and($result->height)->toBe(400);
})->with('drivers');

it('applies edits even with optimization off and keeps the format', function (string $driver) {
    useImageDriver($driver);
    config(['media.optimize.enabled' => false]);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 800, 600), 'image/jpeg', ['flip_h' => true, 'rotate' => 90]);

    expect($result->mime)->toBe('image/jpeg')
        ->and($result->extension)->toBe('jpg')
        ->and($result->width)->toBe(600)->and($result->height)->toBe(800)
        ->and($result->failure)->toBeNull();
})->with('drivers');

it('scales to max side after the crop', function (string $driver) {
    useImageDriver($driver);

    $result = app(OptimizeImage::class)->handle(makeImage('jpg', 4000, 1500), 'image/jpeg', [
        'crop' => ['x' => 0, 'y' => 0, 'w' => 4000, 'h' => 1000],
    ]);

    expect($result->width)->toBe(2560)
        ->and($result->height)->toBeGreaterThanOrEqual(639)->toBeLessThanOrEqual(641);
})->with('drivers');

it('refuses to edit an animated gif', function (string $driver) {
    useImageDriver($driver);

    $gif = "GIF89a\x01\x00\x01\x00\x00\x00\x00\x21\xF9\x04\x00\x00\x00\x00\x00\x21\xF9\x04\x00\x00\x00\x00\x00;";
    $result = app(OptimizeImage::class)->handle($gif, 'image/gif', ['rotate' => 90]);

    expect($result->failure)->toBe('not_editable')
        ->and($result->contents)->toBe($gif)
        ->and($result->optimized)->toBeFalse();
})->with('drivers');
