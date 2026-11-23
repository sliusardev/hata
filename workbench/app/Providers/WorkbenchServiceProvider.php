<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * The test host's own provider. It sets none of the starter kit's defaults (`Date::use(CarbonImmutable)`,
 * `Password::defaults()`, `DB::prohibitDestructiveCommands()`): they are a site's decisions, and the suite runs with
 * Laravel's untouched ones to prove Hata does not depend on them.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    //
}
