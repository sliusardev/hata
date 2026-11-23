<?php

use Hata\Support\Config\HataConfig;

it('fills nested keys a published file lacks', function () {
    $merged = HataConfig::merge(
        ['media' => ['s3' => ['bucket' => null, 'region' => 'x'], 'disk' => 'public']],
        ['media' => []],
    );

    expect($merged['media'])->toHaveKey('s3')
        ->and($merged['media']['s3'])->toHaveKey('bucket')
        ->and(HataConfig::merge(['media' => ['s3' => ['bucket' => null, 'region' => 'x']]], ['media' => ['s3' => ['region' => 'y']]])['media']['s3'])
        ->toBe(['bucket' => null, 'region' => 'y']);
});

it('replaces a list instead of merging it by index', function () {
    $types = ['A', 'B', 'C', 'D'];

    expect(HataConfig::merge(['content' => ['types' => $types]], ['content' => ['types' => ['A']]])['content']['types'])->toBe(['A']);
});
