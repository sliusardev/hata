<?php

namespace Tests\Fixtures\Lists;

use Hata\Contracts\AdminUser;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Test-only bulk action handler: it remembers the records it was called with.
 */
final class ArchiveBulkHandler
{
    /**
     * @var list<list<int|string>>
     */
    public static array $calls = [];

    public static ?string $message = 'Archived <b>all</b>';

    /**
     * Thrown instead of running, when set: a refusal (`ProtectedActionException`) or a `ValidationException`.
     */
    public static ?Throwable $throw = null;

    public function __invoke(Collection $records, AdminUser $actor): ?string
    {
        if (self::$throw !== null) {
            throw self::$throw;
        }

        self::$calls[] = $records->modelKeys();

        return self::$message;
    }
}
