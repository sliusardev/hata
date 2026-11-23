<?php

use Hata\Support\ColorScheme\ColorScheme;
use Hata\Support\ColorScheme\Oklch;

test('the neutral preset keeps the stock look', function () {
    expect(ColorScheme::resolve('neutral', null, null, true)->css())->toBe('');
});

test('an unknown preset or an invalid custom color falls back to the stock look', function (string $scheme, ?string $accent) {
    expect(ColorScheme::resolve($scheme, $accent, 'slate', true)->css())->toBe('');
})->with([
    'unknown preset' => ['purple-ish', null],
    'custom without a color' => ['custom', null],
    'custom with a broken color' => ['custom', 'red;}body{display:none'],
]);

test('a custom scheme uses the company color and the chosen gray', function () {
    $variables = ColorScheme::resolve('custom', '#8A1C7C', 'stone', true)->variables();

    expect($variables['light']['--color-accent'])->toBe('#8a1c7c')
        ->and($variables['light']['--color-zinc-500'])->toBe(ColorScheme::GRAYS['stone'][500]);
});

test('text on buttons is white on dark colors and dark on light ones', function () {
    expect(ColorScheme::resolve('blue', null, null, true)->variables()['light']['--color-accent-foreground'])->toBe('#ffffff')
        ->and(ColorScheme::resolve('amber', null, null, true)->variables()['light']['--color-accent-foreground'])->not->toBe('#ffffff');
});

test('accent text stays readable on white in the light theme and on the dark background', function (string $scheme, ?string $accent) {
    $variables = ColorScheme::resolve($scheme, $accent, 'neutral', true)->variables();

    expect(Oklch::contrast($variables['light']['--color-accent-content'], '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(Oklch::contrast($variables['dark']['--color-accent-content'], ColorScheme::GRAYS['neutral'][800]))->toBeGreaterThanOrEqual(4.5);
})->with([
    ...array_map(fn (string $preset): array => [$preset, null], array_keys(array_filter(ColorScheme::PRESETS))),
    'pale yellow brand' => ['custom', '#ffd500'],
    'very dark brand' => ['custom', '#0b1f3a'],
]);

test('without tinting the sidebar uses the base gray', function () {
    $variables = ColorScheme::resolve('teal', null, null, false)->variables();

    expect($variables['light']['--color-sidebar'])->toBe(ColorScheme::GRAYS['slate'][50])
        ->and($variables['dark']['--color-sidebar'])->toBe(ColorScheme::GRAYS['slate'][900]);
});

test('the stock look can be written out to override a saved scheme in a preview', function () {
    expect(ColorScheme::resolve('neutral', null, null, true)->css(includeStock: true))
        ->toContain('--color-accent:#262626')
        ->toContain('.dark{--color-accent:#ffffff');
});
