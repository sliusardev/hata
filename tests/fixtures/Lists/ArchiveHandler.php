<?php

namespace Tests\Fixtures\Lists;

use Hata\Contracts\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Test-only row action handler, as a module would write one: it remembers what it was called with.
 */
final class ArchiveHandler
{
    /**
     * @var list<array{class-string, mixed, bool}>
     */
    public static array $calls = [];

    public static ?string $message = 'Archived <b>it</b>';

    /**
     * Thrown instead of running, when set: a refusal (`ProtectedActionException`) or a `ValidationException`.
     */
    public static ?Throwable $throw = null;

    public function __invoke(Model $record, AdminUser $user): ?string
    {
        if (self::$throw !== null) {
            throw self::$throw;
        }

        self::$calls[] = [$record::class, $record->getKey(), method_exists($record, 'trashed') && $record->trashed()];

        return self::$message;
    }
}
