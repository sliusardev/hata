<?php

namespace Tests\Fixtures\Widgets;

use Hata\Livewire\Admin\Widget;
use Illuminate\Contracts\View\View;
use Livewire\Livewire;

/**
 * Test-only dashboard widget, as a module would write one: a title and a body view.
 */
class HelloWidget extends Widget
{
    public static function register(): void
    {
        Livewire::component('test-hello-widget', self::class);
        Livewire::component('test-broken-widget', BrokenWidget::class);
        view()->addNamespace('widget-fixtures', dirname(__DIR__, 3).'/workbench/resources/views/widgets');
    }

    public function title(): string
    {
        return 'Hello-Title';
    }

    protected function body(): View
    {
        return view('widget-fixtures::hello', ['greeting' => 'Hello-Body']);
    }

    public function shout(): string
    {
        return strtoupper('from-the-component');
    }
}
