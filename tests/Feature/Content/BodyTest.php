<?php

use App\Models\Media;
use App\Support\Content\BodyRenderer;
use App\Support\Content\BodySanitizer;
use Illuminate\Support\Facades\DB;

dataset('hostile bodies', [
    'script element' => ['<p>a</p><script>alert(1)</script>', '<p>a</p>'],
    'onclick attribute' => ['<p onclick="x()">a</p>', '<p>a</p>'],
    'onerror attribute on an image' => ['<img data-media-id="5" alt="x" onerror="x()">', '<img data-media-id="5" alt="x">'],
    'onmouseover on a link' => ['<a href="/x" onmouseover="x()">a</a>', '<a href="/x">a</a>'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
    'javascript link in mixed case' => ['<a href="JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'],
    'javascript link after whitespace' => ['<a href="  javascript:alert(1)">x</a>', '<a>x</a>'],
    'javascript link with an encoded letter' => ['<a href="&#106;avascript:alert(1)">x</a>', '<a>x</a>'],
    'javascript link with an encoded tab' => ['<a href="jav&#x09;ascript:alert(1)">x</a>', '<a>x</a>'],
    'javascript link with an encoded colon' => ['<a href="javascript&colon;alert(1)">x</a>', '<a>x</a>'],
    'data link' => ['<a href="data:text/html,x">x</a>', '<a>x</a>'],
    'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>', '<a>x</a>'],
    'iframe' => ['<p>a</p><iframe src="https://example.com"></iframe>', '<p>a</p>'],
    'style attribute' => ['<p style="color:red">a</p>', '<p>a</p>'],
    'style element' => ['<style>p{color:red}</style><p>a</p>', '<p>a</p>'],
    'image with a non-numeric id' => ['<p>a</p><img data-media-id="abc" alt="x">', '<p>a</p>'],
    'image with a partly numeric id' => ['<p>a</p><img data-media-id="5abc">', '<p>a</p>'],
    'image with a src and no id' => ['<p>a</p><img src="https://example.com/a.png" alt="x">', '<p>a</p>'],
    'image with a src and an id' => ['<img src="https://example.com/a.png" data-media-id="7" alt="x">', '<img data-media-id="7" alt="x">'],
    'svg' => ['<p>a</p><svg><script>alert(1)</script><circle r="1"></circle></svg>', '<p>a</p>'],
    'math' => ['<p>a</p><math><mi>x</mi></math>', '<p>a</p>'],
    'form with an input' => ['<p>a</p><form action="/x"><input name="a"></form>', '<p>a</p>'],
    'input' => ['<p>a</p><input name="a" value="b">', '<p>a</p>'],
    'unknown attribute on a paragraph' => ['<p class="x" data-foo="1">a</p>', '<p>a</p>'],
    'unknown attribute on a link' => ['<a href="/x" class="y">a</a>', '<a href="/x">a</a>'],
]);

it('removes hostile markup', function (string $input, string $expected) {
    expect((new BodySanitizer)->clean($input))->toBe($expected);
})->with('hostile bodies');

it('keeps the allowed markup', function () {
    $html = '<h2>T</h2><p><strong>b</strong> <em>i</em> <u>u</u> <s>s</s> <code>c</code><br>x</p>'
        .'<ul><li>a</li></ul><ol><li>b</li></ol><blockquote>q</blockquote><hr><pre>p</pre>'
        .'<p><a href="https://example.com/a?b=1&amp;c=2" target="_blank" rel="noopener">l</a> '
        .'<a href="mailto:a@example.com">m</a> <a href="tel:+380501234567">t</a> <a href="/about">r</a></p>'
        .'<img data-media-id="12" alt="Alt">';

    expect((new BodySanitizer)->clean($html))->toBe($html);
});

it('turns an empty result into null', function (?string $input) {
    expect((new BodySanitizer)->clean($input))->toBeNull();
})->with([null, '', '   ', '<script>x</script>']);

it('keeps Cyrillic text unchanged through clean and render', function () {
    $html = '<p>Привіт, світе! Їжак, ґанок, «лапки» — тире.</p><h2>Заголовок</h2>';

    expect((new BodySanitizer)->clean($html))->toBe($html)
        ->and((string) (new BodyRenderer)->render($html))->toBe($html);
});

it('lists the media ids of the images in order, once each', function () {
    $html = '<p>a</p><img data-media-id="3"><p><img data-media-id="1"></p><img data-media-id="3">';

    expect((new BodySanitizer)->mediaIds($html))->toBe([3, 1])
        ->and((new BodySanitizer)->mediaIds('<p>none</p>'))->toBe([])
        ->and((new BodySanitizer)->mediaIds(null))->toBe([]);
});

it('removes the images of one file and keeps the others', function () {
    $html = '<p>a</p><img data-media-id="3" alt="x"><p><img data-media-id="1" alt="y"></p><img data-media-id="3">';

    expect((new BodySanitizer)->withoutMedia($html, 3))->toBe('<p>a</p><p><img data-media-id="1" alt="y"></p>')
        ->and((new BodySanitizer)->withoutMedia('<img data-media-id="3">', 3))->toBeNull()
        ->and((new BodySanitizer)->withoutMedia(null, 3))->toBeNull();
});

it('renders the images from one query', function () {
    $media = Media::factory()->image(640, 480)->create();
    $html = '<p>a</p><img data-media-id="'.$media->id.'" alt="Mine"><p><img data-media-id="'.$media->id.'" alt="Mine"></p>'
        .'<img data-media-id="999999" alt="Gone">';

    DB::enableQueryLog();
    $out = (string) (new BodyRenderer)->render($html);
    $queries = DB::getQueryLog();

    expect($queries)->toHaveCount(1)
        ->and(substr_count($out, '<img'))->toBe(2)
        ->and(substr_count($out, 'src="'.e($media->url()).'"'))->toBe(2)
        ->and($out)->toContain('width="640"', 'height="480"', 'loading="lazy"', 'decoding="async"', 'alt="Mine"')
        ->and($out)->not->toContain('999999');
});

it('falls back to the file alt when the image has none', function () {
    $media = Media::factory()->image()->create(['alt' => ['en' => 'From the file']]);

    $out = (string) (new BodyRenderer)->render('<img data-media-id="'.$media->id.'" alt="">');

    expect($out)->toContain('alt="From the file"');
});

it('drops an image whose file is not an image', function () {
    $document = Media::factory()->document()->create();

    expect((string) (new BodyRenderer)->render('<p>a</p><img data-media-id="'.$document->id.'">'))->toBe('<p>a</p>');
});

it('makes no query for a body without images', function () {
    DB::enableQueryLog();
    $out = (string) (new BodyRenderer)->render('<p>Text</p>');

    expect(DB::getQueryLog())->toBeEmpty()
        ->and($out)->toBe('<p>Text</p>')
        ->and((string) (new BodyRenderer)->render(null))->toBe('');
});

it('does not cut a long body', function () {
    $html = str_repeat('<p>Абзац тексту.</p>', 5000);

    expect((new BodySanitizer)->clean($html))->toBe($html);
});
