<?php

use App\Actions\Media\OptimizeImage;
use App\Support\Media\MemoryGuard;

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

it('converts heic on imagick and reports it unreadable on gd', function () {
    if (! extension_loaded('imagick') || Imagick::queryFormats('HEIC') === []) {
        $this->markTestSkipped('Imagick without HEIC support.');
    }

    try {
        $imagick = new Imagick;
        $imagick->newImage(640, 480, 'red');
        $imagick->setImageFormat('heic');
        $heic = $imagick->getImageBlob();
    } catch (ImagickException) {
        $this->markTestSkipped('Imagick can read HEIC here but not write it.');
    }

    config(['images.default' => 'imagick']);
    $converted = app(OptimizeImage::class)->handle($heic, 'image/heic');

    config(['images.default' => 'gd']);
    $unreadable = app(OptimizeImage::class)->handle($heic, 'image/heic');

    expect($converted->mime)->toBe('image/webp')
        ->and([$converted->width, $converted->height])->toBe([640, 480])
        ->and($converted->optimized)->toBeTrue()
        ->and($unreadable->failure)->toBe('unreadable')
        ->and($unreadable->contents)->toBe($heic)
        ->and($unreadable->extension)->toBe('heic');
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

it('builds the fingerprint from the settings', function () {
    config(['media.optimize.max_side' => null, 'media.optimize.quality' => 70, 'media.optimize.strip_exif' => false]);

    expect(app(OptimizeImage::class)->fingerprint())->toBe('webp:none:70:exif1');
});

it('lets the memory guard pass everything when memory is unlimited', function () {
    expect((new MemoryGuard(limitBytes: -1))->canDecode(20000, 20000))->toBeTrue()
        ->and((new MemoryGuard(limitBytes: 1024 * 1024))->canDecode(2000, 2000))->toBeFalse();
});
