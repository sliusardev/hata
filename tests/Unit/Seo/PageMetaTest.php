<?php

use Hata\Support\Seo\PageMeta;

it('normalises a description', function (?string $in, ?string $out) {
    expect(PageMeta::normalizeDescription($in))->toBe($out);
})->with([
    'tags and entities' => ['<p>Fish &amp; <b>chips</b></p>', 'Fish & chips'],
    'whitespace' => ["  a\n\n  b\t c ", 'a b c'],
    'blank' => ['  <p> </p> ', null],
    'null' => [null, null],
]);

it('cuts a long description on a word boundary with an ellipsis', function () {
    $text = str_repeat('слово ', 40);
    $cut = PageMeta::normalizeDescription($text);

    expect(mb_strlen($cut))->toBeLessThanOrEqual(160)
        ->and($cut)->toEndWith('…')
        ->and(mb_substr($cut, 0, -1))->not->toEndWith(' ')
        ->and(str_starts_with($text, mb_substr($cut, 0, -1)))->toBeTrue();
});

it('keeps a description of exactly 160 characters whole', function () {
    expect(PageMeta::normalizeDescription(str_repeat('a', 160)))->toBe(str_repeat('a', 160));
});
