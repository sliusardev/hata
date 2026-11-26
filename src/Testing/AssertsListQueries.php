<?php

namespace Hata\Testing;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * For the tests of a list (Hata's, a site's or a module's): a list costs the same number of queries whatever its rows.
 */
trait AssertsListQueries
{
    /**
     * Seeds one record, renders the list once to warm the caches, renders again and counts its queries; seeds more up to twenty records and counts again.
     * Fails when the two counts differ, which is a query per row.
     *
     * @param  Closure(int): mixed  $seed  Creates the given number of records, in addition to those already there.
     * @param  Closure(): mixed  $render  Renders the list (a request or a Livewire test).
     */
    protected function assertListQueriesStable(Closure $seed, Closure $render): void
    {
        $seed(1);
        $render(); // warms what is cached once per process (permissions, the registry), so it is not counted
        $few = $this->countListQueries($render);

        $seed(19);
        $many = $this->countListQueries($render);

        Assert::assertSame($few, $many, "The list ran {$few} queries with 1 record and {$many} with 20: a column, filter or action costs a query per row.");
    }

    /**
     * Counts the queries of one render. A query log the test had switched on stays on, with its entries; one that was
     * off is switched off and emptied again.
     *
     * @param  Closure(): mixed  $render
     */
    private function countListQueries(Closure $render): int
    {
        $wasLogging = DB::logging();

        if (! $wasLogging) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }

        $before = count(DB::getQueryLog());

        try {
            $render();

            $count = count(DB::getQueryLog()) - $before;
        } finally {
            if (! $wasLogging) {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }

        return $count;
    }
}
