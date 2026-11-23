<?php

namespace Hata\Support\Assets;

use Hata\Hata;
use Illuminate\Foundation\Vite;

/**
 * Vite that reads Hata's manifest from the package's `dist/` (the host's public copy is a published duplicate), so
 * the tags render the same whether or not the files were published yet.
 */
final class HataVite extends Vite
{
    protected function manifestPath($buildDirectory): string
    {
        return $buildDirectory === HataAssets::DIRECTORY
            ? Hata::path('dist/'.$this->manifestFilename)
            : parent::manifestPath($buildDirectory);
    }
}
