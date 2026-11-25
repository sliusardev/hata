<?php

namespace Tests\Fixtures\Widgets;

use Hata\Livewire\Admin\Widget;
use Illuminate\Contracts\View\View;
use RuntimeException;

/**
 * Test-only dashboard widget whose title fails.
 */
class BrokenTitleWidget extends Widget
{
    public function title(): string
    {
        throw new RuntimeException('no title today');
    }

    protected function body(): View
    {
        return view('widget-fixtures::hello', ['greeting' => 'Hello-Body']);
    }
}
