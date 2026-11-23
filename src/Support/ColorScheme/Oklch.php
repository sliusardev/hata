<?php

namespace Hata\Support\ColorScheme;

/**
 * Conversions between sRGB hex colors and OKLCH, the perceptual color space used to build
 * color scales (same lightness steps for every hue, like Tailwind's palette).
 */
final class Oklch
{
    /**
     * @return array{0: float, 1: float, 2: float} Lightness (0–1), chroma, hue in degrees.
     */
    public static function fromHex(string $hex): array
    {
        [$red, $green, $blue] = self::linearRgb($hex);

        $l = self::cbrt(0.4122214708 * $red + 0.5363325363 * $green + 0.0514459929 * $blue);
        $m = self::cbrt(0.2119034982 * $red + 0.6806995451 * $green + 0.1073969566 * $blue);
        $s = self::cbrt(0.0883024619 * $red + 0.2817188376 * $green + 0.6299787005 * $blue);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        $hue = rad2deg(atan2($b, $a));

        return [$lightness, sqrt($a * $a + $b * $b), $hue < 0 ? $hue + 360 : $hue];
    }

    /**
     * The sRGB hex of an OKLCH color. Chroma is reduced until the color fits in sRGB.
     */
    public static function toHex(float $lightness, float $chroma, float $hue): string
    {
        $low = 0.0;
        $high = $chroma;
        $rgb = self::toLinearRgb($lightness, $chroma, $hue);

        if (! self::inGamut($rgb)) {
            for ($step = 0; $step < 24; $step++) {
                $middle = ($low + $high) / 2;

                if (self::inGamut(self::toLinearRgb($lightness, $middle, $hue))) {
                    $low = $middle;
                } else {
                    $high = $middle;
                }
            }

            $rgb = self::toLinearRgb($lightness, $low, $hue);
        }

        return '#'.implode('', array_map(
            fn (float $channel): string => str_pad(dechex((int) round(max(0.0, min(1.0, self::gammaEncode($channel))) * 255)), 2, '0', STR_PAD_LEFT),
            $rgb,
        ));
    }

    /**
     * WCAG contrast ratio of two hex colors (1–21).
     */
    public static function contrast(string $first, string $second): float
    {
        $luminance = fn (string $hex): float => array_sum(array_map(
            fn (float $channel, float $weight): float => $channel * $weight,
            self::linearRgb($hex),
            [0.2126, 0.7152, 0.0722],
        ));

        $lighter = max($luminance($first), $luminance($second));
        $darker = min($luminance($first), $luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function linearRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            self::gammaDecode(hexdec(substr($hex, 0, 2)) / 255),
            self::gammaDecode(hexdec(substr($hex, 2, 2)) / 255),
            self::gammaDecode(hexdec(substr($hex, 4, 2)) / 255),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function toLinearRgb(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private static function inGamut(array $rgb): bool
    {
        foreach ($rgb as $channel) {
            if ($channel < -0.0001 || $channel > 1.0001) {
                return false;
            }
        }

        return true;
    }

    private static function gammaDecode(float $channel): float
    {
        return $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }

    private static function gammaEncode(float $channel): float
    {
        return $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;
    }

    private static function cbrt(float $value): float
    {
        return $value < 0 ? -((-$value) ** (1 / 3)) : $value ** (1 / 3);
    }
}
