<?php

use Hata\Models\Media;
use Hata\Rules\MediaSelection;
use Illuminate\Support\Facades\Validator;

/**
 * Whether the value passes the rule, and the first message when it doesn't.
 *
 * @return array{0: bool, 1: string|null}
 */
function validateSelection(mixed $value, MediaSelection $rule): array
{
    $validator = Validator::make(['media' => $value], ['media' => [$rule]]);

    return [$validator->passes(), $validator->errors()->first('media') ?: null];
}

it('accepts an allowed image id for a single selection', function () {
    $image = Media::factory()->image()->create();

    expect(validateSelection($image->id, new MediaSelection(accept: 'image', multiple: false, extensions: ['png', 'jpg', 'jpeg', 'webp', 'svg'])))
        ->toBe([true, null])
        ->and(validateSelection((string) $image->id, new MediaSelection(accept: 'image')))->toBe([true, null]);
});

it('accepts an ordered list of allowed ids for a multiple selection', function () {
    [$first, $second] = Media::factory()->image()->count(2)->create()->all();
    $document = Media::factory()->document()->create();

    expect(validateSelection([$second->id, $first->id, $document->id], new MediaSelection(accept: 'any', multiple: true)))
        ->toBe([true, null]);
});

it('rejects a value of the wrong shape', function (mixed $value, bool $multiple) {
    $image = Media::factory()->image()->create();
    $value = $value === 'id' ? $image->id : ($value === 'list' ? [$image->id] : $value);

    expect(validateSelection($value, new MediaSelection(accept: 'image', multiple: $multiple)))
        ->toBe([false, __('media.picker.invalid')]);
})->with([
    'array for single' => ['list', false],
    'int for multiple' => ['id', true],
    'text' => ['abc', false],
    'map instead of list' => [['a' => 1], true],
    'nested list' => [[[1]], true],
]);

it('rejects an id that does not exist', function () {
    expect(validateSelection(999, new MediaSelection(accept: 'image')))
        ->toBe([false, __('media.picker.invalid')]);
});

it('rejects a document where an image is expected', function () {
    $document = Media::factory()->document()->create();

    expect(validateSelection($document->id, new MediaSelection(accept: 'image')))
        ->toBe([false, __('media.picker.invalid')])
        ->and(validateSelection($document->id, new MediaSelection(accept: 'document')))->toBe([true, null]);
});

it('rejects an image whose extension is not in the list', function () {
    $gif = Media::factory()->image()->create(['name' => 'animation.gif', 'mime' => 'image/gif']);
    $logoExtensions = ['png', 'jpg', 'jpeg', 'webp', 'svg'];

    expect(validateSelection($gif->id, new MediaSelection(accept: 'image', extensions: $logoExtensions)))
        ->toBe([false, __('media.picker.invalid')])
        ->and(validateSelection([$gif->id], new MediaSelection(accept: 'image', multiple: true, extensions: $logoExtensions)))
        ->toBe([false, __('media.picker.invalid')]);
});
