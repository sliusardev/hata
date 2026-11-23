<?php

use Hata\Hata;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Every file a site receives must have a plain ASCII name: macOS's unzip cannot extract UTF-8 names from Composer's
 * release archive, and Composer's fallback needs php-zip, which some hosts lack.
 */
it('ships only plain ASCII file names', function () {
    $offending = collect(['src', 'config', 'database', 'resources', 'routes', 'stubs', 'dist'])
        ->flatMap(fn (string $folder) => File::allFiles(Hata::path($folder)))
        ->map(fn (SplFileInfo $file) => Str::after($file->getPathname(), Hata::path().'/'))
        ->reject(fn (string $path) => mb_check_encoding($path, 'ASCII'))
        ->values()
        ->all();

    expect($offending)->toBe([]);
});
