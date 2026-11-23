<?php

namespace Hata\Actions\Maintenance;

use App\Models\User;
use ArrayObject;
use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Actions\Media\OptimizeExistingMedia;
use Hata\Enums\MaintenanceAction;
use Hata\Enums\MaintenanceStatus;
use Hata\Exceptions\MaintenanceException;
use Hata\Models\MaintenanceRun;
use Hata\Support\Maintenance\ArtisanRunner;
use Hata\Support\Maintenance\SeederCatalog;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Run one of the fixed maintenance actions and record it in the journal.
 */
class RunMaintenanceAction
{
    /**
     * Runs kept in the journal.
     */
    public const int KEEP = 200;

    /**
     * Bytes of output kept per run (the end, where errors are).
     */
    public const int OUTPUT_LIMIT = 65536;

    public function __construct(
        private readonly ArtisanRunner $artisan,
        private readonly SeederCatalog $seeders,
    ) {}

    /**
     * "Rebuild caches" runs with the stored site settings switched off, so the config cache holds the `.env`-level
     * values only (see SiteSettings::buildingConfigCache()).
     *
     * The run is recorded before the command starts and finished with its result; a command that throws or
     * returns a non-zero exit code is a failed run, never an exception for the caller. A shutdown function
     * finishes the entry as interrupted when a fatal error (the time limit, memory) cuts the run short.
     *
     * On an install that has not run this feature's own migration yet there is no journal table: then only
     * "Run migrations" is allowed, it runs without the lock, and its entry is written afterwards.
     *
     * @throws MaintenanceException when the action is refused: another run is in progress, the seeder is not on
     *                              the list, a media batch holds cache locks the action would wipe, or the
     *                              journal table is missing and the action is not the migration
     */
    public function handle(User $actor, MaintenanceAction $action, ?string $seeder = null): MaintenanceRun
    {
        Gate::forUser($actor)->authorize('maintenance.run');

        $class = null;

        if ($action === MaintenanceAction::Seed) {
            $class = $this->seeders->resolve((string) $seeder) ?? throw MaintenanceException::unknownSeeder();
        }

        if ($action === MaintenanceAction::ClearCaches && $this->mediaBatchRunning()) {
            throw MaintenanceException::mediaBatchRunning();
        }

        $hasJournal = Schema::hasTable((new MaintenanceRun)->getTable());

        if (! $hasJournal && $action !== MaintenanceAction::Migrate) {
            throw MaintenanceException::migrateFirst();
        }

        $run = $this->start($actor, $action, $class === null ? null : (string) $seeder, save: $hasJournal);
        $state = new ArrayObject(['finished' => false]);

        register_shutdown_function(function () use ($run, $state): void {
            if (! $state['finished'] && $run->exists) {
                $this->finish($run, MaintenanceStatus::Interrupted, null, __('hata::maintenance.errors.interrupted'));
            }
        });

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        try {
            [$command, $parameters] = $action->command($class);
            $result = $action === MaintenanceAction::RebuildCaches
                ? SiteSettings::withoutApplying(fn (): array => $this->artisan->run($command, $parameters))
                : $this->artisan->run($command, $parameters);
            $status = $result['exit_code'] === 0 ? MaintenanceStatus::Succeeded : MaintenanceStatus::Failed;
            $exitCode = $result['exit_code'];
            $output = $result['output'];
        } catch (Throwable $exception) {
            report($exception);
            $status = MaintenanceStatus::Failed;
            $exitCode = null;
            $output = $exception::class.': '.$exception->getMessage();
        }

        if ($hasJournal || Schema::hasTable($run->getTable())) {
            $this->finish($run, $status, $exitCode, $output);
            $this->prune();
        } else {
            $this->fill($run, $status, $exitCode, $output);
        }

        $state['finished'] = true;

        return $run;
    }

    /**
     * Take the run lock by creating the journal entry: the unique `slot` lets only one running entry exist. The
     * lock is a row, not a cache lock, because "Clear caches" empties the cache store on some drivers. An entry
     * that has been running for STALE_MINUTES is taken for dead and released first; a run that really takes
     * longer loses its lock then, which is accepted.
     */
    private function start(User $actor, MaintenanceAction $action, ?string $parameter, bool $save): MaintenanceRun
    {
        $run = new MaintenanceRun([
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'action' => $action,
            'parameter' => $parameter,
            'status' => MaintenanceStatus::Running,
            'slot' => MaintenanceRun::SLOT,
            'started_at' => now(),
        ]);

        if (! $save) {
            return $run;
        }

        MaintenanceRun::query()
            ->where('slot', MaintenanceRun::SLOT)
            ->where('started_at', '<', now()->subMinutes(MaintenanceRun::STALE_MINUTES))
            ->update(['slot' => null, 'status' => MaintenanceStatus::Interrupted, 'finished_at' => now()]);

        try {
            $run->save();
        } catch (UniqueConstraintViolationException) {
            throw MaintenanceException::busy();
        }

        return $run;
    }

    private function finish(MaintenanceRun $run, MaintenanceStatus $status, ?int $exitCode, string $output): void
    {
        $this->fill($run, $status, $exitCode, $output)->save();
    }

    /**
     * Put the result on the entry: the output is made valid UTF-8 (a database in strict mode refuses anything
     * else) and cut to its last OUTPUT_LIMIT bytes.
     */
    private function fill(MaintenanceRun $run, MaintenanceStatus $status, ?int $exitCode, string $output): MaintenanceRun
    {
        $output = trim(mb_scrub($output, 'UTF-8'));
        $truncated = strlen($output) > self::OUTPUT_LIMIT;

        return $run->forceFill([
            'status' => $status,
            'slot' => null,
            'exit_code' => $exitCode,
            'output' => $truncated ? mb_scrub(substr($output, -self::OUTPUT_LIMIT), 'UTF-8') : $output,
            'output_truncated' => $truncated,
            'finished_at' => now(),
        ]);
    }

    private function prune(): void
    {
        $oldestKept = MaintenanceRun::query()->orderByDesc('id')->skip(self::KEEP - 1)->value('id');

        if ($oldestKept !== null) {
            MaintenanceRun::query()->where('id', '<', $oldestKept)->delete();
        }
    }

    /**
     * Media batches hold cache locks, which clearing the cache wipes on the file and redis stores.
     */
    private function mediaBatchRunning(): bool
    {
        return app(OptimizeExistingMedia::class)->isBatchRunning() || app(MoveMediaToDisk::class)->isBatchRunning();
    }
}
