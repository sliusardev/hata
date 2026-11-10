<?php

use App\Enums\MediaKind;
use App\Support\Media\MediaTypes;

it('detects allowed files by content', function (string $name, string $bytes, string $ext, MediaKind $kind) {
    $detected = MediaTypes::detect(uploadedImage($name, $bytes));

    expect($detected)->not->toBeNull()
        ->and($detected['ext'])->toBe($ext)
        ->and($detected['kind'])->toBe($kind);
})->with([
    'jpeg' => ['photo.jpg', fn () => makeImage('jpg'), 'jpg', MediaKind::Image],
    'pdf' => ['doc.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF", 'pdf', MediaKind::Document],
    'mp3' => ['song.mp3', "ID3\x03\x00\x00\x00\x00\x00\x00".str_repeat("\xFF\xFB\x90\x00", 300), 'mp3', MediaKind::Other],
]);

it('rejects a php file renamed to jpg', function () {
    expect(MediaTypes::detect(uploadedImage('x.jpg', '<?php echo 1;')))->toBeNull();
});

it('rejects unknown types', function () {
    expect(MediaTypes::detect(uploadedImage('design.psd', "8BPS\x00\x01".str_repeat("\0", 20))))->toBeNull();
});

it('serves text as plain text attachments', function () {
    expect(MediaTypes::responseMime('text/csv'))->toBe('text/plain; charset=UTF-8')
        ->and(MediaTypes::disposition('text/csv'))->toBe('attachment')
        ->and(MediaTypes::disposition('application/pdf'))->toBe('inline');
});

it('builds test image helpers', function () {
    expect(getimagesizefromstring(makeImage('png', 40, 30))[0])->toBe(40)
        ->and(getimagesizefromstring(withExif(makeImage('jpg', 10, 10))))->not->toBeFalse()
        ->and(substr_count(animatedGif(), "\x21\xF9\x04"))->toBe(2)
        ->and(MediaTypes::detect(uploadedImage('a.gif', animatedGif()))['ext'])->toBe('gif');
});

it('rejects binary garbage named docx', function () {
    expect(MediaTypes::detect(uploadedImage('a.docx', random_bytes(64))))->toBeNull();
});

it('accepts a zip container named docx', function () {
    $zip = tempnam(sys_get_temp_dir(), 'zip');
    $archive = new ZipArchive;
    $archive->open($zip, ZipArchive::OVERWRITE);
    $archive->addFromString('[Content_Types].xml', '<Types/>');
    $archive->close();
    $bytes = file_get_contents($zip);
    unlink($zip);

    expect(MediaTypes::detect(uploadedImage('a.docx', $bytes))['ext'] ?? null)->toBe('docx');
});

it('detects heic by its ftyp brand', function () {
    $bytes = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1".str_repeat("\0", 32);

    expect(MediaTypes::detect(uploadedImage('a.heic', $bytes))['ext'] ?? null)->toBe('heic');
    expect(MediaTypes::detect(uploadedImage('a.heic', random_bytes(64))))->toBeNull();
});

it('rejects xml without an svg root named svg', function () {
    expect(MediaTypes::detect(uploadedImage('a.svg', '<?xml version="1.0"?><html><body/></html>')))->toBeNull();

    $svg = '<?xml version="1.0"?><!-- c --><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>';
    expect(MediaTypes::detect(uploadedImage('b.svg', $svg))['ext'] ?? null)->toBe('svg');
});

it('never reports html as an html mime', function () {
    $detected = MediaTypes::detect(uploadedImage('a.txt', '<html><body>hi</body></html>'));

    expect($detected === null || $detected['mime'] === 'text/plain')->toBeTrue();
});
