<?php

namespace Tests\Fixtures\Widgets;

use Hata\Livewire\Admin\Widget;
use Illuminate\Contracts\View\View;
use RuntimeException;

/**
 * Test-only dashboard widget whose data work fails.
 */
class BrokenWidget extends Widget
{
    public function title(): string
    {
        return 'Broken-Title';
    }

    protected function body(): View
    {
        throw new RuntimeException('the data source is down');
    }
}
