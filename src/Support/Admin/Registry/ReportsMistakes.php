<?php

namespace Hata\Support\Admin\Registry;

use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * How a registry reports a registration mistake: thrown where a developer sees it (local and testing), skipped
 * elsewhere and logged once per process and registry class, so a production site still renders its admin and a
 * long-running worker does not repeat the warning. Shared by `Registry` and `TargetedRegistry`.
 */
trait ReportsMistakes
{
    /**
     * The registration mistakes this process has logged already, per registry class.
     *
     * @var array<class-string, array<string, true>>
     */
    private static array $warned = [];

    /**
     * Forgets which mistakes were logged. For tests only.
     *
     * @internal
     */
    public static function forgetWarnings(): void
    {
        unset(self::$warned[static::class]);
    }

    private function report(string $message): void
    {
        if (app()->environment('local', 'testing')) {
            throw new LogicException($message);
        }

        if (isset(self::$warned[static::class][$message])) {
            return;
        }

        self::$warned[static::class][$message] = true;

        Log::warning($message);
    }
}
