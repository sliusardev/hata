<?php

namespace Tests\Fixtures;

use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * An application whose config switches the sitemap and robots.txt routes off before Hata's routes load, as
 * `HATA_SITEMAP=false` and `HATA_ROBOTS=false` do on a site that serves its own.
 */
abstract class SeoRoutesOffTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hata.seo.sitemap', false);
        $app['config']->set('hata.seo.robots', false);
    }
}
