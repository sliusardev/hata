<?php

namespace Hata\Support\ColorScheme;

/**
 * The color scheme of the admin panel and the sign-in pages: an accent color and a base gray.
 *
 * It overrides the CSS variables of Tailwind and Flux (--color-zinc-*, --color-accent*,
 * --color-sidebar*) with an inline style in <head>, so no CSS rebuild is needed. The accent
 * scale is generated from one color in OKLCH; the dark theme uses lighter steps of it.
 */
final class ColorScheme
{
    public const string CUSTOM = 'custom';

    /**
     * Preset key => [accent color, base gray]. "neutral" is the stock look (no overrides).
     *
     * @var array<string, array{0: string, 1: string}|null>
     */
    public const array PRESETS = [
        'neutral' => null,
        'blue' => ['#2563eb', 'slate'],
        'indigo' => ['#4f46e5', 'slate'],
        'violet' => ['#7c3aed', 'zinc'],
        'rose' => ['#e11d48', 'zinc'],
        'orange' => ['#ea580c', 'stone'],
        'amber' => ['#f59e0b', 'stone'],
        'emerald' => ['#059669', 'zinc'],
        'teal' => ['#0d9488', 'slate'],
    ];

    /**
     * Base gray scales (Tailwind), written over --color-zinc-*, which Flux uses everywhere.
     *
     * @var array<string, array<int, string>>
     */
    public const array GRAYS = [
        'neutral' => [50 => '#fafafa', 100 => '#f5f5f5', 200 => '#e5e5e5', 300 => '#d4d4d4', 400 => '#a3a3a3', 500 => '#737373', 600 => '#525252', 700 => '#404040', 800 => '#262626', 900 => '#171717', 950 => '#0a0a0a'],
        'zinc' => [50 => '#fafafa', 100 => '#f4f4f5', 200 => '#e4e4e7', 300 => '#d4d4d8', 400 => '#a1a1aa', 500 => '#71717a', 600 => '#52525b', 700 => '#3f3f46', 800 => '#27272a', 900 => '#18181b', 950 => '#09090b'],
        'slate' => [50 => '#f8fafc', 100 => '#f1f5f9', 200 => '#e2e8f0', 300 => '#cbd5e1', 400 => '#94a3b8', 500 => '#64748b', 600 => '#475569', 700 => '#334155', 800 => '#1e293b', 900 => '#0f172a', 950 => '#020617'],
        'stone' => [50 => '#fafaf9', 100 => '#f5f5f4', 200 => '#e7e5e4', 300 => '#d6d3d1', 400 => '#a8a29e', 500 => '#78716c', 600 => '#57534e', 700 => '#44403c', 800 => '#292524', 900 => '#1c1917', 950 => '#0c0a09'],
        'gray' => [50 => '#f9fafb', 100 => '#f3f4f6', 200 => '#e5e7eb', 300 => '#d1d5db', 400 => '#9ca3af', 500 => '#6b7280', 600 => '#4b5563', 700 => '#374151', 800 => '#1f2937', 900 => '#111827', 950 => '#030712'],
    ];

    /**
     * OKLCH lightness of each scale step (close to Tailwind's palette).
     *
     * @var array<int, float>
     */
    private const array LIGHTNESS = [50 => 0.971, 100 => 0.936, 200 => 0.885, 300 => 0.808, 400 => 0.704, 500 => 0.637, 600 => 0.577, 700 => 0.505, 800 => 0.444, 900 => 0.396, 950 => 0.282];

    /**
     * Share of the accent's chroma kept at each step: pale steps are nearly gray.
     *
     * @var array<int, float>
     */
    private const array CHROMA = [50 => 0.08, 100 => 0.16, 200 => 0.32, 300 => 0.55, 400 => 0.8, 500 => 0.95, 600 => 1.0, 700 => 0.9, 800 => 0.75, 900 => 0.6, 950 => 0.45];

    /**
     * @param  string|null  $accent  Accent color (#rrggbb), null for the stock neutral look.
     */
    public function __construct(
        public readonly ?string $accent,
        public readonly string $gray = 'neutral',
        public readonly bool $tintSidebar = true,
    ) {}

    /**
     * Build a scheme from a preset key, or from the custom color and gray for "custom".
     * An unknown preset or an invalid custom color falls back to the stock look.
     */
    public static function resolve(string $scheme, ?string $customAccent, ?string $customGray, bool $tintSidebar): self
    {
        if ($scheme === self::CUSTOM) {
            return self::isHexColor($customAccent)
                ? new self(strtolower((string) $customAccent), array_key_exists((string) $customGray, self::GRAYS) ? (string) $customGray : 'neutral', $tintSidebar)
                : new self(null);
        }

        $preset = self::PRESETS[$scheme] ?? null;

        return $preset === null ? new self(null) : new self($preset[0], $preset[1], $tintSidebar);
    }

    /**
     * The scheme chosen in the site settings.
     */
    public static function current(): self
    {
        return self::resolve(
            (string) config('hata.admin.colors.scheme'),
            config('hata.admin.colors.accent'),
            config('hata.admin.colors.gray'),
            (bool) config('hata.admin.colors.tint_sidebar'),
        );
    }

    public static function isHexColor(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * Accent scale from 50 to 950. Empty for the stock look.
     *
     * @return array<int, string>
     */
    public function scale(): array
    {
        if ($this->accent === null) {
            return [];
        }

        [, $chroma, $hue] = Oklch::fromHex($this->accent);

        $scale = [];

        foreach (self::LIGHTNESS as $step => $lightness) {
            $scale[$step] = Oklch::toHex($lightness, $chroma * self::CHROMA[$step], $hue);
        }

        return $scale;
    }

    /**
     * CSS variables for the light theme (:root) and the dark theme (.dark).
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function variables(bool $includeStock = false): array
    {
        if ($this->accent === null) {
            return $includeStock ? self::stockVariables() : ['light' => [], 'dark' => []];
        }

        $scale = $this->scale();
        $gray = self::GRAYS[$this->gray];
        $light = [];

        foreach ($gray as $step => $hex) {
            $light["--color-zinc-{$step}"] = $hex;
        }

        $light['--color-accent'] = $this->accent;
        $light['--color-accent-content'] = $this->readableOn('#ffffff', [$this->accent, $scale[600], $scale[700], $scale[800], $scale[900]]);
        $light['--color-accent-foreground'] = $this->foregroundFor($this->accent, $scale[950]);
        $light['--color-sidebar'] = $this->tintSidebar ? $scale[50] : $gray[50];
        $light['--color-sidebar-border'] = $this->tintSidebar ? $scale[100] : $gray[200];

        // A light color (amber, yellow) stays itself in the dark theme; a darker one uses step 500.
        $darkAccent = Oklch::fromHex($this->accent)[0] >= self::LIGHTNESS[500] ? $this->accent : $scale[500];

        $dark = [
            '--color-accent' => $darkAccent,
            '--color-accent-content' => $this->readableOn($gray[800], [$darkAccent, $scale[400], $scale[300], $scale[200], $scale[100]]),
            '--color-accent-foreground' => $this->foregroundFor($darkAccent, $scale[950]),
            '--color-sidebar' => $this->tintSidebar ? $scale[950] : $gray[900],
            '--color-sidebar-border' => $this->tintSidebar ? $scale[900] : $gray[700],
        ];

        return ['light' => $light, 'dark' => $dark];
    }

    /**
     * The inline stylesheet, or an empty string for the stock look. With $includeStock, the stock
     * look is written out too, so a preview can override a saved scheme.
     */
    public function css(bool $includeStock = false): string
    {
        $variables = $this->variables($includeStock);

        if ($variables['light'] === []) {
            return '';
        }

        $declarations = fn (array $values): string => implode('', array_map(
            fn (string $name, string $value): string => "{$name}:{$value};",
            array_keys($values),
            $values,
        ));

        return ':root{'.$declarations($variables['light']).'}.dark{'.$declarations($variables['dark']).'}';
    }

    /**
     * The values of resources/css/app.css (neutral gray, near-black accent, white in dark mode).
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    private static function stockVariables(): array
    {
        $gray = self::GRAYS['neutral'];
        $light = [];

        foreach ($gray as $step => $hex) {
            $light["--color-zinc-{$step}"] = $hex;
        }

        return [
            'light' => [
                ...$light,
                '--color-accent' => $gray[800],
                '--color-accent-content' => $gray[800],
                '--color-accent-foreground' => '#ffffff',
                '--color-sidebar' => $gray[50],
                '--color-sidebar-border' => $gray[200],
            ],
            'dark' => [
                '--color-accent' => '#ffffff',
                '--color-accent-content' => '#ffffff',
                '--color-accent-foreground' => $gray[800],
                '--color-sidebar' => $gray[900],
                '--color-sidebar-border' => $gray[700],
            ],
        ];
    }

    /**
     * Text on accent buttons: white while it keeps the 3:1 contrast WCAG asks of interface
     * elements, otherwise the darkest accent step (amber, yellow, light brand colors).
     */
    private function foregroundFor(string $background, string $darkest): string
    {
        return Oklch::contrast($background, '#ffffff') >= 3.0 ? '#ffffff' : $darkest;
    }

    /**
     * The first candidate with a 4.5:1 contrast on the background (WCAG AA for text).
     *
     * Falls back to the last (most contrasting) candidate.
     *
     * @param  non-empty-list<string>  $candidates
     */
    private function readableOn(string $background, array $candidates): string
    {
        $candidate = $candidates[0];

        foreach ($candidates as $candidate) {
            if (Oklch::contrast($candidate, $background) >= 4.5) {
                return $candidate;
            }
        }

        return $candidate;
    }
}
