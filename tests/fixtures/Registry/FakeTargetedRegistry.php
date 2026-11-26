<?php

namespace Tests\Fixtures\Registry;

use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * Test-only targeted registry over FakeRegistry.
 *
 * @extends TargetedRegistry<FakeRegistry>
 */
final class FakeTargetedRegistry extends TargetedRegistry
{
    public function name(): string
    {
        return 'fake entry';
    }

    protected function newRegistry(): Registry
    {
        return new FakeRegistry;
    }
}
