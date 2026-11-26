<?php

namespace Tests\Fixtures\Lists;

use Hata\Contracts\AdminUser;
use Illuminate\Database\Eloquent\Collection;

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

    public function __invoke(Collection $records, AdminUser $actor): ?string
    {
        self::$calls[] = $records->modelKeys();

        return self::$message;
    }
}
