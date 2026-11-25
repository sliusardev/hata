<?php

namespace Tests\Fixtures\Registry;

use Closure;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;

/**
 * Test-only registry entry: the three fields every entry has, and a label to tell versions of one key apart.
 */
final readonly class FakeEntry implements Entry
{
    use HasChanges;

    public function __construct(
        public string $key,
        public int $order = 100,
        public string|Closure|null $permission = null,
        public string $label = '',
    ) {}
}
