<?php

namespace Hata\Support\Localization;

/**
 * One language of the catalogue (config/internal/languages.php).
 */
final readonly class Language
{
    public function __construct(
        public string $code,
        public string $name,
        public string $native,
        public bool $rtl = false,
    ) {}

    /**
     * The code as `hreflang` and `<html lang>` want it: "pt-br" → "pt-BR", "zh-hans" → "zh-Hans".
     */
    public function hreflang(): string
    {
        $parts = explode('-', $this->code);

        foreach ($parts as $index => $part) {
            if ($index === 0) {
                continue;
            }

            $parts[$index] = strlen($part) === 4 ? ucfirst($part) : strtoupper($part);
        }

        return implode('-', $parts);
    }

    public function direction(): string
    {
        return $this->rtl ? 'rtl' : 'ltr';
    }

    /**
     * Whether the admin and the sign-in pages are translated into this language (a lang/<code>/ folder listed
     * in `hata.admin.interface_locales`).
     */
    public function hasInterface(): bool
    {
        return array_key_exists($this->code, (array) config('hata.admin.interface_locales'));
    }
}
