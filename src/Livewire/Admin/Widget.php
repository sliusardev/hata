<?php

namespace Hata\Livewire\Admin;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

/**
 * The base of a dashboard widget. A subclass gives `title()` and `body()`; the card frame, the lazy placeholder and
 * the error card are this class's. The dashboard loads widgets lazily in one bundled request, so `body()` (where all
 * data work belongs) never delays the page.
 */
abstract class Widget extends Component
{
    abstract public function title(): string;

    /**
     * The widget's content, without the card: all of the widget's data work happens here, so a failure of it is caught
     * by `render()`. The view itself is rendered inside the card by Livewire (where `$this` is the widget), so a mistake
     * in its markup is not turned into the error card.
     */
    abstract protected function body(): View;

    public function render(): View
    {
        try {
            $content = $this->body();
            $failed = false;
        } catch (Throwable $exception) {
            if (app()->environment('local', 'testing')) {
                throw $exception;
            }

            Log::warning('The dashboard widget ['.static::class.'] failed: '.$exception->getMessage(), ['exception' => $exception]);
            $content = null;
            $failed = true;
        }

        return view('hata::components.admin.widget-card', [
            'title' => $this->title(),
            'content' => $content,
            'failed' => $failed,
        ]);
    }

    public function placeholder(): string
    {
        return view('hata::components.admin.widget-card', ['title' => $this->title(), 'loading' => true])->render();
    }
}
