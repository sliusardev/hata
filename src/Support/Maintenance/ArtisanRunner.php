<?php

namespace Hata\Support\Maintenance;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs an Artisan command inside the current process and returns what it printed. The one place the
 * maintenance page touches Artisan, so tests can replace it instead of rebuilding real caches.
 */
class ArtisanRunner
{
    /**
     * `config:cache` and `route:cache` (so `optimize` too) boot a second application to collect the config and
     * routes. Booting it re-points process-wide state at that throwaway application: the container instance,
     * the facades, and Eloquent's connection resolver and event dispatcher. Left like that, the rest of the
     * request (Livewire building its response, the journal entry being saved) runs against the wrong
     * application, so all of it is put back whatever the command did.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{exit_code: int, output: string}
     */
    public function run(string $command, array $parameters = []): array
    {
        $container = Container::getInstance();
        $facadeApplication = Facade::getFacadeApplication();
        $connectionResolver = Model::getConnectionResolver();
        $eventDispatcher = Model::getEventDispatcher();

        try {
            $output = new BufferedOutput(decorated: false);
            $exitCode = Artisan::call($command, $parameters, $output);

            return ['exit_code' => $exitCode, 'output' => $output->fetch()];
        } finally {
            Container::setInstance($container);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($facadeApplication);

            if ($connectionResolver !== null) {
                Model::setConnectionResolver($connectionResolver);
            }

            if ($eventDispatcher !== null) {
                Model::setEventDispatcher($eventDispatcher);
            }
        }
    }
}
