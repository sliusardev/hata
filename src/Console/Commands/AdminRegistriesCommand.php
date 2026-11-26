<?php

namespace Hata\Console\Commands;

use Closure;
use Hata\Admin;
use Hata\Support\Admin\Forms\FormCards;
use Hata\Support\Admin\Lists\BulkActions;
use Hata\Support\Admin\Lists\RowActions;
use Hata\Support\Admin\Lists\TableColumns;
use Hata\Support\Admin\Lists\TableFilters;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;
use Illuminate\Console\Command;

/**
 * Lists what the admin registries hold, so a developer sees what a site or module changed.
 */
class AdminRegistriesCommand extends Command
{
    protected $signature = 'hata:admin';

    protected $description = 'List the admin menu, top bar, dashboard widgets, settings sections, list columns, filters and actions, and form cards';

    public function handle(): int
    {
        $this->show('Admin menu', Admin::menu());
        $this->show('Top bar', Admin::topbar());
        $this->show('Dashboard widgets', Admin::dashboard());
        $this->show('Settings sections', Admin::settings());

        $this->showTargets('List columns', app(TableColumns::class));
        $this->showTargets('List filters', app(TableFilters::class));
        $this->showTargets('Row actions', app(RowActions::class));
        $this->showTargets('Bulk actions', app(BulkActions::class));
        $this->showTargets('Form cards', app(FormCards::class));

        return self::SUCCESS;
    }

    /**
     * One table per target that received a call (`users`, `content.*`, `content.posts`, ...): the entries that
     * target's list or form resolves, Hata's own included.
     *
     * @param  TargetedRegistry<*>  $registry
     */
    private function showTargets(string $title, TargetedRegistry $registry): void
    {
        foreach ($registry->targets() as $target) {
            $this->show("{$title} [{$target}]", $registry->resolve($target));
        }
    }

    /**
     * @template TEntry of Entry
     *
     * @param  Registry<TEntry>  $registry
     */
    private function show(string $title, Registry $registry): void
    {
        $report = $registry->inspect();

        $this->newLine();
        $this->components->info($title);

        $this->table(
            ['Key', 'Order', 'Permission', 'What'],
            array_map(fn (Entry $entry): array => [
                $entry->key,
                $entry->order,
                $entry->permission instanceof Closure ? 'closure' : ($entry->permission ?? 'everyone'),
                $registry->describe($entry),
            ], $report['entries']),
        );

        if ($report['unmatched'] !== []) {
            $this->line('Unmatched: '.implode(', ', $report['unmatched']));
        }

        foreach ($report['unchanged'] as $key => $problem) {
            $this->line("Unchanged: [{$key}] {$problem}");
        }

        if ($report['problems'] !== []) {
            $this->line('Left out:');

            foreach ($report['problems'] as $key => $problem) {
                $this->line("  [{$key}] {$problem}");
            }
        }
    }
}
