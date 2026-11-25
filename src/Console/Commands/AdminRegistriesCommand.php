<?php

namespace Hata\Console\Commands;

use Closure;
use Hata\Admin;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\Registry;
use Illuminate\Console\Command;

/**
 * Lists what the admin registries hold, so a developer sees what a site or module changed.
 */
class AdminRegistriesCommand extends Command
{
    protected $signature = 'hata:admin';

    protected $description = 'List the admin menu, top bar, dashboard widgets and settings sections';

    public function handle(): int
    {
        $this->show('Admin menu', Admin::menu());
        $this->show('Top bar', Admin::topbar());
        $this->show('Dashboard widgets', Admin::dashboard());
        $this->show('Settings sections', Admin::settings());

        return self::SUCCESS;
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

        if ($report['problems'] !== []) {
            $this->line('Left out:');

            foreach ($report['problems'] as $key => $problem) {
                $this->line("  [{$key}] {$problem}");
            }
        }
    }
}
