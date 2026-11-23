<?php

use Hata\Support\Media\ImageEdits;

it('round-trips the spec shape', function () {
    $input = [
        'rotate' => 90,
        'flip_h' => true,
        'flip_v' => false,
        'crop' => ['x' => 10, 'y' => 20, 'w' => 300, 'h' => 200],
        'size' => ['w' => 150, 'h' => 100],
    ];

    $edits = ImageEdits::fromArray($input);

    expect($edits)->not->toBeNull()
        ->and($edits->toArray())->toBe($input)
        ->and($edits->isEmpty())->toBeFalse()
        ->and(ImageEdits::fromArray(null))->toBeNull()
        ->and(ImageEdits::fromArray([]))->toBeNull()
        ->and((new ImageEdits)->isEmpty())->toBeTrue();
});

it('rejects invalid edits', function (array $input) {
    ImageEdits::fromArray($input);
})->with([
    'rotate 45' => [['rotate' => 45]],
    'crop width 0' => [['crop' => ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 10]]],
    'size height negative' => [['size' => ['w' => 10, 'h' => -1]]],
    'crop with a string' => [['crop' => ['x' => 'a', 'y' => 0, 'w' => 10, 'h' => 10]]],
])->throws(InvalidArgumentException::class);

it('computes the output size', function () {
    $crop = ['x' => 0, 'y' => 0, 'w' => 1000, 'h' => 500];

    expect((new ImageEdits(rotate: 90, crop: $crop))->outputSize(4000, 3000))->toBe([1000, 500])
        ->and((new ImageEdits(rotate: 90, crop: $crop, size: ['w' => 400, 'h' => 400]))->outputSize(4000, 3000))->toBe([400, 200])
        ->and((new ImageEdits(rotate: 90, crop: $crop, size: ['w' => 2000, 'h' => 2000]))->outputSize(4000, 3000))->toBe([1000, 500])
        ->and((new ImageEdits(rotate: 90))->outputSize(4000, 3000))->toBe([3000, 4000]);
});
