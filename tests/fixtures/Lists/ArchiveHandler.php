<?php

namespace Tests\Fixtures\Lists;

use Hata\Contracts\AdminUser;
use Illuminate\Database\Eloquent\Model;

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

    public function __invoke(Model $record, AdminUser $user): ?string
    {
        self::$calls[] = [$record::class, $record->getKey(), method_exists($record, 'trashed') && $record->trashed()];

        return self::$message;
    }
}
