<?php

namespace Hata\Livewire\Admin\Widgets;

use Hata\Contracts\AdminUser;
use Hata\Livewire\Admin\Widget;
use Hata\Models\Entry;
use Hata\Support\Localization\ContentLocales;
use Illuminate\Contracts\View\View;

/**
 * Dashboard widget: the records edited last among the visible types. One query on `entries`, no author.
 */
class RecentChangesWidget extends Widget
{
    use VisibleContentTypes;

    public function title(): string
    {
        return __('hata::dashboard.widgets.recent_changes.title');
    }

    protected function body(): View
    {
        /** @var AdminUser $user */
        $user = auth()->user();
        $types = self::visibleTypesFor($user);
        $entries = $types === [] ? collect() : Entry::query()
            ->whereIn('type', array_keys($types))
            ->latest('updated_at')
            ->limit(8)
            ->get(['id', 'type', 'locale', 'title', 'status', 'updated_at']);

        return view('hata::livewire.admin.widgets.recent-changes', [
            'entries' => $entries,
            'types' => $types,
            'showLocale' => count(app(ContentLocales::class)->active()) > 1,
            'timezone' => config('hata.admin.timezone'),
        ]);
    }
}
