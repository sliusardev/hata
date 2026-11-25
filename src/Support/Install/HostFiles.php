<?php

namespace Hata\Support\Install;

use Hata\Hata;
use Illuminate\Support\Facades\File;
use ParseError;
use stdClass;

/**
 * The edits `hata:install` makes to a host's own files. Each edit takes the file's path, works on the shape Pint
 * gives Laravel's skeleton (as the content-type generator does with config and language files, decision 62), and is
 * idempotent: a file that already has the change is not written, and a file of another shape is left untouched,
 * with the lines to add by hand in the result. An edited PHP file must still parse, or it is not written either.
 *
 * Paths are always parameters: nothing here reads the application's own paths.
 */
final class HostFiles
{
    /**
     * The commands a site's `post-update-cmd` needs: every `composer update` republishes every package's assets
     * (Hata's among them), and clears the compiled views, because Blade recompiles a template only when its own file
     * changes, not when an updated Hata resolves a component differently.
     *
     * @var list<string>
     */
    public const array POST_UPDATE_COMMANDS = [
        '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
        '@php artisan view:clear --ansi',
    ];

    /**
     * The command a site's `post-autoload-dump` needs, right after `package:discover`: it runs on `composer install`
     * too, so a deploy that installs from the lock file (`git pull && composer install --no-dev`) also publishes the
     * built assets of the Hata it installed. Without it the views would print tags for hashed files that are not in
     * `public/vendor/hata`.
     *
     * @var list<string>
     */
    public const array POST_AUTOLOAD_DUMP_COMMANDS = [
        '@php artisan vendor:publish --tag=hata-assets --force --ansi',
    ];

    /**
     * The command in `post-autoload-dump` that Hata's publish follows: it must run after the packages are discovered.
     */
    private const string PACKAGE_DISCOVER = '@php artisan package:discover --ansi';

    /**
     * Laravel's skeleton `run()` body of `DatabaseSeeder`: a test user from the factory (which needs Faker, a dev
     * dependency) with the password "password", replaced by Hata's seeding (decision 13).
     */
    private const string SKELETON_SEEDER_BODY = <<<'PHP'
                // User::factory(10)->create();

                User::factory()->create([
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                ]);

        PHP;

    /**
     * `implements AdminUser` and `use IsAdminUser` on the host's user model, with their imports.
     */
    public function addUserTrait(string $userModel): HostFileResult
    {
        $manual = $this->stub('user-model.stub');
        $source = $this->read($userModel);

        if ($source === null || ! str_contains($source, "use Illuminate\\Foundation\\Auth\\User as Authenticatable;\n")) {
            return HostFileResult::unrecognized($manual);
        }

        $hasInterface = preg_match('/^(?:final |abstract )?class \w+ extends Authenticatable implements [^{]*\bAdminUser\b/m', $source) === 1;
        $hasTrait = preg_match('/^    use [^;(]*\bIsAdminUser\b[^;]*;$/m', $source) === 1;

        if ($hasInterface && $hasTrait) {
            return HostFileResult::alreadyDone();
        }

        $updated = $source;

        if (! $hasInterface) {
            $updated = preg_replace_callback(
                '/^((?:final )?class \w+ extends Authenticatable)(?: implements ([\w\\\\]+(?:, [\w\\\\]+)*))?\n(?=\{\n)/m',
                fn (array $match): string => $match[1].' implements '.implode(', ', [...array_filter(explode(', ', $match[2] ?? '')), 'AdminUser'])."\n",
                $updated,
                1,
                $count,
            );

            $updated = $count === 1 ? $this->addImports((string) $updated, ['Hata\\Contracts\\AdminUser']) : null;
        }

        if (! $hasTrait && $updated !== null) {
            $classStart = (int) strpos($updated, "\n{\n");

            if (preg_match('/^    use ([\w, ]+);$/m', $updated, $match, PREG_OFFSET_CAPTURE, $classStart) === 1) {
                $traits = $this->insertSorted(explode(', ', $match[1][0]), 'IsAdminUser');
                $updated = substr_replace($updated, implode(', ', $traits), $match[1][1], strlen($match[1][0]));
                $updated = $this->addImports($updated, ['Hata\\Models\\Concerns\\IsAdminUser']);
            } else {
                $updated = null;
            }
        }

        return $this->write($userModel, $source, $updated, $manual);
    }

    /**
     * Removes the skeleton's `/` route to the `welcome` view: Hata registers the home page at `/`, and a host route
     * there would win over it. The view itself is deleted by the command.
     */
    public function removeWelcomeRoute(string $webRoutes): HostFileResult
    {
        $manual = $this->stub('welcome-route.stub');

        $source = $this->read($webRoutes);

        if ($source === null) {
            return HostFileResult::unrecognized($manual);
        }

        $updated = preg_replace(
            [
                "/\n?^Route::get\\('\\/', function \\(\\) \\{\n    return view\\('welcome'\\);\n\\}\\);\n/m",
                "/\n?^Route::view\\('\\/', 'welcome'\\);\n/m",
            ],
            '',
            $source,
        );

        if ($updated === null || $this->hasHomeRoute($updated)) {
            return HostFileResult::unrecognized($manual);
        }

        if ($updated === $source) {
            return HostFileResult::alreadyDone();
        }

        if (! str_contains($updated, 'Route::')) {
            $updated = str_replace("use Illuminate\\Support\\Facades\\Route;\n", '', $updated);
            $updated = trim(substr($updated, strlen('<?php'))) === '' ? "<?php\n" : str_replace("\n\n\n", "\n\n", $updated);
        }

        return $this->write($webRoutes, $source, $updated, $manual);
    }

    /**
     * `app/Providers/HataAdminServiceProvider.php`, written from the stub when it is missing (an existing file is the
     * site's own and is never overwritten), and listed in `bootstrap/providers.php` as
     * `ServiceProvider::addProviderToBootstrapFile()` lists it: already listed means unchanged, the list sorted. The
     * providers file is edited only in Laravel's skeleton shape (one `Class::class,` per line); otherwise it is left
     * untouched, with the line to add.
     */
    public function addAdminProvider(string $providerPath, string $bootstrapProviders): HostFileResult
    {
        $class = 'App\\Providers\\HataAdminServiceProvider';
        $manual = "Add this line to the list in bootstrap/providers.php:\n\n    {$class}::class,";

        $source = $this->read($bootstrapProviders);

        if ($source === null || preg_match('/\A<\?php\n\nreturn \[\n((?:    [\w\\\\]+::class,\n)*)\];\n\z/', $source, $match) !== 1) {
            return HostFileResult::unrecognized($manual);
        }

        $providerWritten = false;

        if (! is_file($providerPath)) {
            File::ensureDirectoryExists(dirname($providerPath));
            $providerWritten = file_put_contents($providerPath, $this->stub('hata-admin-service-provider.stub')) !== false;
        }

        $listed = array_filter(array_map('trim', explode("\n", $match[1])));
        $isListed = in_array($class.'::class,', $listed, true);

        if ($isListed) {
            return $providerWritten ? HostFileResult::changed() : HostFileResult::alreadyDone();
        }

        $entries = array_unique([...array_map(fn (string $line): string => substr($line, 0, -strlen('::class,')), $listed), $class]);
        sort($entries);

        file_put_contents($bootstrapProviders, "<?php\n\nreturn [\n".implode("\n", array_map(fn (string $entry): string => "    {$entry}::class,", $entries))."\n];\n");

        return HostFileResult::changed();
    }

    /**
     * Deletes Laravel's stock `public/robots.txt` (it allows everything and names no sitemap): the web server would
     * serve it before Hata's `/robots.txt` route, which adds the `Sitemap:` line. Only that exact content goes; a file
     * the site has changed is its own and is left alone, with the line to add.
     */
    public function removeStockRobots(string $robots): HostFileResult
    {
        $source = $this->read($robots);

        if ($source === null) {
            return HostFileResult::alreadyDone();
        }

        if (trim(str_replace("\r\n", "\n", $source)) !== "User-agent: *\nDisallow:") {
            return HostFileResult::unrecognized("Add this line to the site's own robots.txt, so crawlers find the sitemap:\n\nSitemap: ".url('sitemap.xml'));
        }

        return @unlink($robots) ? HostFileResult::changed() : HostFileResult::unrecognized('Delete public/robots.txt: Hata serves /robots.txt itself, with the sitemap address.');
    }

    /**
     * The starter kit's `configureDefaults()` (immutable dates, no destructive DB commands in production, strict
     * passwords in production), called first in `boot()`. Hata itself never sets these: they are the site's choice.
     */
    public function addHostDefaults(string $appServiceProvider): HostFileResult
    {
        $method = $this->stub('app-service-provider-defaults.stub');
        $imports = ['Carbon\\CarbonImmutable', 'Illuminate\\Support\\Facades\\Date', 'Illuminate\\Support\\Facades\\DB', 'Illuminate\\Validation\\Rules\\Password'];
        $manual = implode("\n", array_map(fn (string $class): string => "use {$class};", $imports))
            ."\n\n// first in boot():\n\$this->configureDefaults();\n\n".$method;

        $source = $this->read($appServiceProvider);

        if ($source === null) {
            return HostFileResult::unrecognized($manual);
        }

        $hasMethod = str_contains($source, 'function configureDefaults(');
        $isCalled = str_contains($source, '$this->configureDefaults();');

        if ($hasMethod && $isCalled) {
            return HostFileResult::alreadyDone();
        }

        if ($hasMethod || $isCalled
            || preg_match('/^    public function boot\(\): void\n    \{\n(.*?)^    \}\n/ms', $source, $boot, PREG_OFFSET_CAPTURE) !== 1
            || ! str_ends_with($source, "    }\n}\n")) {
            return HostFileResult::unrecognized($manual);
        }

        $body = $boot[1][0] === "        //\n" ? '' : $boot[1][0];
        $updated = substr_replace($source, "        \$this->configureDefaults();\n".$body, $boot[1][1], strlen($boot[1][0]));
        $updated = substr($updated, 0, -strlen("}\n"))."\n".$method."}\n";

        return $this->write($appServiceProvider, $source, $this->addImports($updated, $imports), $manual);
    }

    /**
     * `$this->call(HataSeeder::class);` first in the host's `DatabaseSeeder`. The skeleton's test user goes, and so
     * does its `WithoutModelEvents`: Hata's models fill columns (the search index) from their events.
     */
    public function addSeederCall(string $databaseSeeder): HostFileResult
    {
        $manual = <<<'TEXT'
            use Hata\Database\Seeders\HataSeeder;

            // first in run(), in a seeder that does not use WithoutModelEvents (Hata's models need their events):
            $this->call(HataSeeder::class);
            TEXT;

        $source = $this->read($databaseSeeder);

        if ($source === null) {
            return HostFileResult::unrecognized($manual);
        }

        if (str_contains($source, 'HataSeeder::class')) {
            return HostFileResult::alreadyDone();
        }

        $call = "        \$this->call(HataSeeder::class);\n";
        $runStart = "    public function run(): void\n    {\n";

        if (str_contains($source, $runStart.self::SKELETON_SEEDER_BODY."    }\n")) {
            $updated = str_replace($runStart.self::SKELETON_SEEDER_BODY, $runStart.$call, $source);
            $updated = str_replace(["    use WithoutModelEvents;\n\n", "use Illuminate\\Database\\Console\\Seeds\\WithoutModelEvents;\n"], '', $updated);

            if (! str_contains($updated, 'User::')) {
                $updated = str_replace("use App\\Models\\User;\n", '', $updated);
            }
        } elseif (substr_count($source, $runStart) === 1 && ! str_contains($source, 'WithoutModelEvents')) {
            $updated = str_replace($runStart, $runStart.$call, $source);
        } else {
            return HostFileResult::unrecognized($manual);
        }

        return $this->write($databaseSeeder, $source, $this->addImports($updated, ['Hata\\Database\\Seeders\\HataSeeder']), $manual);
    }

    /**
     * The commands of `POST_AUTOLOAD_DUMP_COMMANDS` in `scripts.post-autoload-dump` (after `package:discover`) and of
     * `POST_UPDATE_COMMANDS` in `scripts.post-update-cmd` of the host's composer.json. The JSON is read and checked as
     * data; the missing commands are written into the existing text (4 spaces, as Composer writes it), so the rest of
     * the file keeps its formatting. Only when the text has an unusual layout is the whole file re-encoded.
     */
    public function ensureAssetsPublishScript(string $composerJson): HostFileResult
    {
        $scripts = [
            'post-autoload-dump' => self::POST_AUTOLOAD_DUMP_COMMANDS,
            'post-update-cmd' => self::POST_UPDATE_COMMANDS,
        ];
        $manual = 'In composer.json, "scripts" → "post-autoload-dump", after '.$this->jsonString(self::PACKAGE_DISCOVER).":\n"
            .implode(",\n", array_map(fn (string $command): string => $this->jsonString($command), self::POST_AUTOLOAD_DUMP_COMMANDS))
            ."\n\nIn composer.json, \"scripts\" → \"post-update-cmd\":\n"
            .implode(",\n", array_map(fn (string $command): string => $this->jsonString($command), self::POST_UPDATE_COMMANDS));

        $source = $this->read($composerJson);
        $data = $source === null ? null : json_decode($source);

        if (! $data instanceof stdClass
            || (isset($data->scripts) && ! $data->scripts instanceof stdClass)) {
            return HostFileResult::unrecognized($manual);
        }

        $updated = (string) $source;
        $changed = false;

        foreach ($scripts as $key => $commands) {
            $current = $data->scripts->{$key} ?? [];
            $current = is_string($current) ? [$current] : $current;

            if (! is_array($current) || ! array_is_list($current) || array_filter($current, 'is_string') !== $current) {
                return HostFileResult::unrecognized($manual);
            }

            $missing = array_values(array_diff($commands, $current));

            if ($missing === []) {
                continue;
            }

            $after = $key === 'post-autoload-dump' && in_array(self::PACKAGE_DISCOVER, $current, true) ? self::PACKAGE_DISCOVER : null;
            $at = $after === null ? count($current) : (int) array_search($after, $current, true) + 1;

            $data->scripts ??= new stdClass;
            $data->scripts->{$key} = [...array_slice($current, 0, $at), ...$missing, ...array_slice($current, $at)];
            $updated = $updated === null ? null : $this->insertJsonCommands($updated, $key, $missing, $after);
            $changed = true;
        }

        if (! $changed) {
            return HostFileResult::alreadyDone();
        }

        if ($updated === null || json_decode($updated) != $data) {
            $updated = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        return $this->write($composerJson, (string) $source, $updated, $manual);
    }

    /**
     * Adds commands to a script's array in the text: after the line of `$after` when it is there, else at the end of
     * the array, or as a new array first in "scripts".
     *
     * @param  list<string>  $commands
     */
    private function insertJsonCommands(string $source, string $key, array $commands, ?string $after = null): ?string
    {
        $quotedKey = preg_quote($this->jsonString($key), '/');

        if (preg_match('/^( *)'.$quotedKey.': \[\n((?:.*\n)*?)\1\]/m', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $indent = $match[1][0].'    ';
            $items = $match[2][0] === '' ? [] : explode("\n", rtrim($match[2][0], "\n"));
            $items = array_map(fn (string $line): string => rtrim($line, ','), $items);
            $lines = array_map(fn (string $command): string => $indent.$this->jsonString($command), $commands);
            $position = $after === null ? false : array_search($indent.$this->jsonString($after), $items, true);
            $position = $position === false ? count($items) : $position + 1;
            array_splice($items, $position, 0, $lines);

            return substr_replace($source, implode(",\n", $items)."\n", $match[2][1], strlen($match[2][0]));
        }

        if (str_contains($source, $this->jsonString($key))) {
            return null;
        }

        if (preg_match('/^( *)"scripts": \{\n( *)"/m', $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $indent = $match[2][0];
            $lines = array_map(fn (string $command): string => $indent.'    '.$this->jsonString($command), $commands);
            $entry = $indent.$this->jsonString($key).": [\n".implode(",\n", $lines)."\n".$indent."],\n";
            $at = $match[0][1] + strlen($match[1][0].'"scripts": {'."\n");

            return substr_replace($source, $entry, $at, 0);
        }

        return null;
    }

    private function jsonString(string $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Whether routes/web.php still declares a `/` route or renders the welcome view.
     */
    private function hasHomeRoute(string $source): bool
    {
        return preg_match('/Route::\w+\(\s*[\'"]\/[\'"]/', $source) === 1
            || preg_match('/[\'"]welcome[\'"]/', $source) === 1;
    }

    /**
     * Adds `use` lines to the file's top-level imports, each where Pint's alphabetical order puts it. Returns null
     * when the file has no top-level `use` line to place them by.
     *
     * @param  list<string>  $classes
     */
    private function addImports(?string $source, array $classes): ?string
    {
        foreach ($classes as $class) {
            if ($source === null) {
                return null;
            }

            if (preg_match('/^use '.preg_quote($class, '/').';$/m', $source) === 1) {
                continue;
            }

            $header = (string) preg_replace('/^(?:(?:final|abstract|readonly) )*(?:class|interface|trait|enum) .*/ms', '', $source);

            preg_match_all('/^use ([\w\\\\]+)(?: as \w+)?;$/m', $header, $imports, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            $at = null;
            $afterLast = null;

            foreach ($imports as $import) {
                if ($this->compareImports($class, $import[1][0]) < 0) {
                    $at = $import[0][1];

                    break;
                }

                $afterLast = $import[0][1] + strlen($import[0][0]) + 1;
            }

            $at ??= $afterLast;

            if ($at === null) {
                return null;
            }

            $source = substr_replace($source, "use {$class};\n", $at, 0);
        }

        return $source;
    }

    /**
     * The order of PHP-CS-Fixer's `ordered_imports` (alpha): case-insensitive, with `\` read as a space.
     */
    private function compareImports(string $first, string $second): int
    {
        return strcasecmp(str_replace('\\', ' ', $first), str_replace('\\', ' ', $second));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function insertSorted(array $names, string $name): array
    {
        foreach ($names as $index => $existing) {
            if (strcasecmp($name, $existing) < 0) {
                array_splice($names, $index, 0, [$name]);

                return $names;
            }
        }

        return [...$names, $name];
    }

    /**
     * Writes the edited file, unless the edit failed or (for PHP) the result no longer parses.
     */
    private function write(string $path, string $source, ?string $updated, string $manual): HostFileResult
    {
        if ($updated === null) {
            return HostFileResult::unrecognized($manual);
        }

        if ($updated === $source) {
            return HostFileResult::alreadyDone();
        }

        if (str_ends_with($path, '.php')) {
            try {
                $parses = token_get_all($updated, TOKEN_PARSE) !== [];
            } catch (ParseError) {
                $parses = false;
            }

            if (! $parses) {
                return HostFileResult::unrecognized($manual);
            }
        }

        file_put_contents($path, $updated);

        return HostFileResult::changed();
    }

    private function read(string $path): ?string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        return $contents === false ? null : $contents;
    }

    private function stub(string $name): string
    {
        return (string) file_get_contents(Hata::path('stubs/'.$name));
    }
}
