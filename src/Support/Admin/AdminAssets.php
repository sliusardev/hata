<?php

namespace Hata\Support\Admin;

use Illuminate\Foundation\Vite;
use Illuminate\Support\HtmlString;

/**
 * The registry of a module's own admin CSS and JS.
 *
 * A module's admin pages may use Tailwind classes that Hata's built CSS lacks, so a module ships its prebuilt assets
 * (published under its own directory in `public`) and registers them here; the admin `<head>` renders them after
 * Hata's own tags. Bound as a singleton.
 */
final class AdminAssets
{
    /**
     * @var list<array{buildDirectory: string, entries: list<string>, hotFile: ?string, manifestFilename: ?string}>
     */
    private array $registered = [];

    /**
     * @param  string  $buildDirectory  The module's published build directory, relative to `public`.
     * @param  list<string>  $entries  Entry paths as in the module's Vite manifest.
     * @param  ?string  $hotFile  The module's dev-server file, if it has one.
     * @param  ?string  $manifestFilename  The manifest's name in the build directory; by default `.vite/manifest.json`
     *                                     (Vite 5+) and then `manifest.json` are tried.
     */
    public function add(string $buildDirectory, array $entries, ?string $hotFile = null, ?string $manifestFilename = null): void
    {
        $this->registered[] = compact('buildDirectory', 'entries', 'hotFile', 'manifestFilename');
    }

    public function tags(): HtmlString
    {
        $html = '';

        foreach ($this->registered as $module) {
            $vite = (new Vite)
                ->useBuildDirectory($module['buildDirectory'])
                ->useManifestFilename($module['manifestFilename'] ?? $this->manifestFilename($module['buildDirectory']));

            if ($module['hotFile'] !== null) {
                $vite->useHotFile($module['hotFile']);
            }

            $html .= $vite($module['entries'])->toHtml()."\n";
        }

        return new HtmlString($html);
    }

    private function manifestFilename(string $buildDirectory): string
    {
        return is_file(public_path($buildDirectory.'/.vite/manifest.json')) ? '.vite/manifest.json' : 'manifest.json';
    }
}
