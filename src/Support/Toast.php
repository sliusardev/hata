<?php

namespace Hata\Support;

/**
 * Toast notifications that survive a redirect.
 *
 * Flux::toast() only reaches the browser within the current Livewire request. Use this
 * helper before redirecting; the <x-flash-toast /> component in the layouts shows it.
 */
class Toast
{
    public const string SESSION_KEY = 'toast';

    public static function success(string $text): void
    {
        self::flash($text, 'success');
    }

    public static function danger(string $text): void
    {
        self::flash($text, 'danger');
    }

    public static function flash(string $text, ?string $variant = null): void
    {
        session()->flash(self::SESSION_KEY, [
            'text' => $text,
            'variant' => $variant,
        ]);
    }
}
