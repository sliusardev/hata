<?php

namespace Hata\Livewire\Admin\Widgets;

use Hata\Contracts\AdminUser;
use Hata\Enums\EntryStatus;
use Hata\Livewire\Admin\Widget;
use Hata\Models\Entry;
use Illuminate\Contracts\View\View;

/**
 * Dashboard widget: how many records each visible content type holds. One grouped query on `entries`.
 */
class ContentWidget extends Widget
{
    use VisibleContentTypes;

    public function title(): string
    {
        return __('hata::dashboard.widgets.content.title');
    }

    protected function body(): View
    {
        $types = self::visibleTypesFor($this->user());
        $counts = $types === [] ? collect() : Entry::query()
            ->whereIn('type', array_keys($types))
            ->groupBy('type', 'status')
            ->selectRaw('type, status, count(*) as total')
            ->get()
            ->groupBy('type');

        $rows = [];
        foreach ($types as $key => $type) {
            $published = $drafts = 0;
            foreach ($counts[$key] ?? [] as $row) {
                match ($row->status) {
                    EntryStatus::Published => $published = (int) $row->total,
                    EntryStatus::Draft => $drafts = (int) $row->total,
                    default => null,
                };
            }
            $rows[] = [
                'label' => $type->pluralLabel(),
                'url' => route('admin.content.index', $key),
                'published' => $published,
                'drafts' => $drafts,
                'total' => $published + $drafts,
                'publication' => $type->supports('publication'),
            ];
        }

        return view('hata::livewire.admin.widgets.content', ['rows' => $rows]);
    }

    private function user(): AdminUser
    {
        /** @var AdminUser */
        return auth()->user();
    }
}
