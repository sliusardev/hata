<?php

namespace Tests\Fixtures\Registry;

use Hata\Support\Admin\Registry\Registry;

/**
 * Test-only registry over FakeEntry, with the base's defaults.
 *
 * @extends Registry<FakeEntry>
 */
final class FakeRegistry extends Registry
{
    public function name(): string
    {
        return 'fake entry';
    }
}
