<?php

namespace Tests\Fixtures;

use Illuminate\Foundation\Application;
use Tests\TestCase;

/**
 * An application whose config holds a custom admin path and no sitemap route before Hata's routes load, as `HATA_ADMIN_PATH` does on a site.
 */
abstract class CustomAdminPathTestCase extends TestCase
{
    public const string PATH = 'cp-7f3k';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('hata.admin.path', self::PATH);
        $app['config']->set('hata.seo.sitemap', false);
    }
}
