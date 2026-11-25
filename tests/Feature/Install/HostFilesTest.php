<?php

use Hata\Support\Install\HostFiles;
use Hata\Support\Install\HostFileStatus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A temporary copy of a file of Laravel 13's skeleton (tests/fixtures/host), optionally rewritten first.
 */
function hostFile(string $relative, ?Closure $rewrite = null): string
{
    $path = test()->hostDirectory.'/'.basename($relative);
    $source = File::get(dirname(__DIR__, 2).'/fixtures/host/'.$relative);
    File::put($path, $rewrite ? $rewrite($source) : $source);

    return $path;
}

beforeEach(function () {
    $this->hostDirectory = sys_get_temp_dir().'/hata-host-files-'.Str::random(8);
    File::ensureDirectoryExists($this->hostDirectory);
});

afterEach(function () {
    File::deleteDirectory($this->hostDirectory);
});

/**
 * Runs an edit twice: the first run changes the file into `$expected`, the second leaves it byte-identical.
 */
function expectEditedOnceInto(string $path, Closure $edit, string $expected): void
{
    expect($edit($path)->status)->toBe(HostFileStatus::Changed)
        ->and(File::get($path))->toBe($expected);

    expect($edit($path)->status)->toBe(HostFileStatus::AlreadyDone)
        ->and(File::get($path))->toBe($expected);
}

/**
 * An edit that does not recognize the file leaves it untouched and returns the lines to add by hand.
 */
function expectLeftUntouched(string $path, Closure $edit, string $line): void
{
    $before = File::get($path);
    $result = $edit($path);

    expect($result->status)->toBe(HostFileStatus::Unrecognized)
        ->and($result->lines)->toContain($line)
        ->and(File::get($path))->toBe($before);
}

describe('addUserTrait', function () {
    it('makes the skeleton user an admin user', function () {
        $path = hostFile('app/Models/User.php');
        $skeleton = File::get($path);

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->addUserTrait($file), str_replace(
            [
                "use Database\\Factories\\UserFactory;\n",
                "class User extends Authenticatable\n",
                'use HasFactory, Notifiable;',
            ],
            [
                "use Database\\Factories\\UserFactory;\nuse Hata\\Contracts\\AdminUser;\nuse Hata\\Models\\Concerns\\IsAdminUser;\n",
                "class User extends Authenticatable implements AdminUser\n",
                'use HasFactory, IsAdminUser, Notifiable;',
            ],
            $skeleton,
        ));
    });

    it('keeps the interfaces the user already implements', function () {
        $path = hostFile('app/Models/User.php', fn (string $source) => str_replace(
            ["// use Illuminate\\Contracts\\Auth\\MustVerifyEmail;\n", "class User extends Authenticatable\n"],
            ['', "class User extends Authenticatable implements MustVerifyEmail\n"],
            $source,
        ));

        app(HostFiles::class)->addUserTrait($path);

        expect(File::get($path))->toContain("class User extends Authenticatable implements MustVerifyEmail, AdminUser\n");
    });

    it('leaves a user that extends another class alone', function () {
        $path = hostFile('app/Models/User.php', fn (string $source) => str_replace(
            'class User extends Authenticatable',
            'class User extends BaseUser',
            $source,
        ));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->addUserTrait($file), 'use Hata\Models\Concerns\IsAdminUser;');
    });
});

describe('removeWelcomeRoute', function () {
    it('removes the skeleton\'s welcome route', function () {
        $path = hostFile('routes/web.php');

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->removeWelcomeRoute($file), "<?php\n");
    });

    it('keeps the site\'s other routes and their import', function () {
        $path = hostFile('routes/web.php', fn (string $source) => $source."\nRoute::get('/contact', fn () => 'contact');\n");

        app(HostFiles::class)->removeWelcomeRoute($path);

        expect(File::get($path))->toBe("<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/contact', fn () => 'contact');\n");
    });

    it('leaves a changed welcome route alone', function () {
        $path = hostFile('routes/web.php', fn (string $source) => str_replace("view('welcome')", "view('welcome', ['year' => 2026])", $source));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->removeWelcomeRoute($file), "return view('welcome');");
    });
});

describe('removeStockRobots', function () {
    it('deletes the stock file, whatever its line endings and trailing newline', function (string $contents) {
        $path = hostFile('public/robots.txt', fn () => $contents);

        expect(app(HostFiles::class)->removeStockRobots($path)->status)->toBe(HostFileStatus::Changed)
            ->and(File::exists($path))->toBeFalse();
    })->with([
        'unix' => "User-agent: *\nDisallow:\n",
        'crlf' => "User-agent: *\r\nDisallow:\r\n",
        'no newline' => "User-agent: *\nDisallow:",
        'extra blank lines' => "User-agent: *\nDisallow:\n\n\n",
    ]);

    it('keeps a changed file and prints the line to add', function () {
        $path = hostFile('public/robots.txt', fn () => "User-agent: *\nDisallow: /private\n");

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->removeStockRobots($file), 'Sitemap: '.url('sitemap.xml'));
    });

    it('has nothing to do without the file', function () {
        $path = $this->hostDirectory.'/robots.txt';

        expect(app(HostFiles::class)->removeStockRobots($path)->status)->toBe(HostFileStatus::AlreadyDone);
    });
});

describe('addHostDefaults', function () {
    it('adds the starter kit\'s defaults to the skeleton provider', function () {
        $path = hostFile('app/Providers/AppServiceProvider.php');

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->addHostDefaults($file), <<<'PHP'
            <?php

            namespace App\Providers;

            use Carbon\CarbonImmutable;
            use Illuminate\Support\Facades\Date;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\ServiceProvider;
            use Illuminate\Validation\Rules\Password;

            class AppServiceProvider extends ServiceProvider
            {
                /**
                 * Register any application services.
                 */
                public function register(): void
                {
                    //
                }

                /**
                 * Bootstrap any application services.
                 */
                public function boot(): void
                {
                    $this->configureDefaults();
                }

                /**
                 * Configure default behaviors for production-ready applications.
                 */
                protected function configureDefaults(): void
                {
                    Date::use(CarbonImmutable::class);

                    DB::prohibitDestructiveCommands(
                        app()->isProduction(),
                    );

                    Password::defaults(fn (): ?Password => app()->isProduction()
                        ? Password::min(12)
                            ->mixedCase()
                            ->letters()
                            ->numbers()
                            ->symbols()
                            ->uncompromised()
                        : null,
                    );
                }
            }

            PHP);
    });

    it('calls the defaults before the provider\'s own boot code', function () {
        $path = hostFile('app/Providers/AppServiceProvider.php', fn (string $source) => preg_replace(
            '/(public function boot\(\): void\n    \{\n)        \/\/\n/',
            "$1        Model::preventLazyLoading();\n",
            $source,
        ));

        app(HostFiles::class)->addHostDefaults($path);

        expect(File::get($path))->toContain("    {\n        \$this->configureDefaults();\n        Model::preventLazyLoading();\n    }\n");
    });

    it('leaves a provider without the skeleton\'s boot method alone', function () {
        $path = hostFile('app/Providers/AppServiceProvider.php', fn (string $source) => str_replace('public function boot(): void', 'public function boot()', $source));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->addHostDefaults($file), 'Date::use(CarbonImmutable::class);');
    });
});

describe('addSeederCall', function () {
    it('replaces the skeleton\'s test user with Hata\'s seeding', function () {
        $path = hostFile('database/seeders/DatabaseSeeder.php');

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->addSeederCall($file), <<<'PHP'
            <?php

            namespace Database\Seeders;

            use Hata\Database\Seeders\HataSeeder;
            use Illuminate\Database\Seeder;

            class DatabaseSeeder extends Seeder
            {
                /**
                 * Seed the application's database.
                 */
                public function run(): void
                {
                    $this->call(HataSeeder::class);
                }
            }

            PHP);
    });

    it('calls Hata\'s seeding before the site\'s own', function () {
        $path = hostFile('database/seeders/DatabaseSeeder.php', fn (string $source) => str_replace(
            ["use Illuminate\\Database\\Console\\Seeds\\WithoutModelEvents;\n", "    use WithoutModelEvents;\n\n", "'Test User'"],
            ['', '', "'Site Owner'"],
            $source,
        ));

        app(HostFiles::class)->addSeederCall($path);

        expect(File::get($path))->toContain("    {\n        \$this->call(HataSeeder::class);\n        // User::factory(10)->create();\n");
    });

    it('leaves a changed seeder that runs without model events alone', function () {
        $path = hostFile('database/seeders/DatabaseSeeder.php', fn (string $source) => str_replace("'Test User'", "'Site Owner'", $source));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->addSeederCall($file), '$this->call(HataSeeder::class);');
    });
});

describe('ensureAssetsPublishScript', function () {
    it('adds Hata\'s publish after package:discover and view:clear after the skeleton\'s asset publish, keeping the rest of the file', function () {
        $path = hostFile('composer.json');
        $skeleton = File::get($path);

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->ensureAssetsPublishScript($file), str_replace(
            [
                "            \"@php artisan package:discover --ansi\"\n",
                "            \"@php artisan vendor:publish --tag=laravel-assets --ansi --force\"\n",
            ],
            [
                "            \"@php artisan package:discover --ansi\",\n            \"@php artisan vendor:publish --tag=hata-assets --force --ansi\"\n",
                "            \"@php artisan vendor:publish --tag=laravel-assets --ansi --force\",\n            \"@php artisan view:clear --ansi\"\n",
            ],
            $skeleton,
        ));
    });

    it('puts Hata\'s publish right after package:discover when other commands follow it', function () {
        $path = hostFile('composer.json', fn (string $source) => str_replace(
            "            \"@php artisan package:discover --ansi\"\n",
            "            \"@php artisan package:discover --ansi\",\n            \"@php artisan filament:upgrade\"\n",
            $source,
        ));

        expect(app(HostFiles::class)->ensureAssetsPublishScript($path)->status)->toBe(HostFileStatus::Changed)
            ->and(json_decode(File::get($path), true)['scripts']['post-autoload-dump'])->toBe([
                'Illuminate\\Foundation\\ComposerScripts::postAutoloadDump',
                '@php artisan package:discover --ansi',
                '@php artisan vendor:publish --tag=hata-assets --force --ansi',
                '@php artisan filament:upgrade',
            ]);
    });

    it('accepts scripts written as a single string', function () {
        $path = hostFile('composer.json', function (string $source) {
            $data = json_decode($source, true);
            $data['scripts']['post-autoload-dump'] = '@php artisan package:discover --ansi';
            $data['scripts']['post-update-cmd'] = '@php artisan view:clear --ansi';

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        });

        expect(app(HostFiles::class)->ensureAssetsPublishScript($path)->status)->toBe(HostFileStatus::Changed);

        $scripts = json_decode(File::get($path), true)['scripts'];

        expect($scripts['post-autoload-dump'])->toBe([
            '@php artisan package:discover --ansi',
            '@php artisan vendor:publish --tag=hata-assets --force --ansi',
        ])
            ->and($scripts['post-update-cmd'])->toBe([
                '@php artisan view:clear --ansi',
                '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
            ])
            ->and(app(HostFiles::class)->ensureAssetsPublishScript($path)->status)->toBe(HostFileStatus::AlreadyDone);
    });

    it('adds the commands to a composer.json without post-update-cmd or post-autoload-dump', function () {
        $path = hostFile('composer.json', function (string $source) {
            $data = json_decode($source, true);
            unset($data['scripts']['post-update-cmd'], $data['scripts']['post-autoload-dump']);

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        });

        expect(app(HostFiles::class)->ensureAssetsPublishScript($path)->status)->toBe(HostFileStatus::Changed);

        $data = json_decode(File::get($path), true);

        expect($data['scripts']['post-update-cmd'])->toBe([
            '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
            '@php artisan view:clear --ansi',
        ])
            ->and($data['scripts']['post-autoload-dump'])->toBe(['@php artisan vendor:publish --tag=hata-assets --force --ansi'])
            ->and($data['scripts']['setup'])->toHaveCount(6);
    });

    it('leaves a composer.json it cannot read alone', function () {
        $path = hostFile('composer.json', fn (string $source) => str_replace('"scripts": {', '"scripts": {,', $source));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->ensureAssetsPublishScript($file), '"@php artisan vendor:publish --tag=hata-assets --force --ansi"');
    });

    it('leaves a composer.json whose post-autoload-dump is not a list of commands alone', function () {
        $path = hostFile('composer.json', fn (string $source) => str_replace(
            "\"post-autoload-dump\": [\n            \"Illuminate\\\\Foundation\\\\ComposerScripts::postAutoloadDump\",\n            \"@php artisan package:discover --ansi\"\n        ]",
            '"post-autoload-dump": {"discover": "@php artisan package:discover --ansi"}',
            $source,
        ));

        expect(File::get($path))->toContain('"post-autoload-dump": {');

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->ensureAssetsPublishScript($file), '"@php artisan view:clear --ansi"');
    });
});

describe('addAdminProvider', function () {
    it('writes the admin provider and lists it', function () {
        $providers = hostFile('bootstrap/providers.php');
        $provider = $this->hostDirectory.'/HataAdminServiceProvider.php';

        $result = app(HostFiles::class)->addAdminProvider($provider, $providers);

        expect($result->status)->toBe(HostFileStatus::Changed)
            ->and(File::get($provider))->toContain('class HataAdminServiceProvider extends ServiceProvider', 'php artisan hata:admin', 'docs/specs/admin-extensions.md')
            ->and(File::get($providers))->toBe("<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n    App\\Providers\\HataAdminServiceProvider::class,\n];\n")
            ->and(substr_count(File::get($providers), 'HataAdminServiceProvider::class'))->toBe(1);

        exec('php -l '.escapeshellarg($provider).' 2>&1', $output, $code);
        expect($code)->toBe(0, implode("\n", $output));

        $again = app(HostFiles::class)->addAdminProvider($provider, $providers);
        expect($again->status)->toBe(HostFileStatus::AlreadyDone)
            ->and(substr_count(File::get($providers), 'HataAdminServiceProvider::class'))->toBe(1);
    });

    it('leaves an existing provider untouched', function () {
        $providers = hostFile('bootstrap/providers.php');
        $provider = $this->hostDirectory.'/HataAdminServiceProvider.php';
        File::put($provider, "<?php\n// the site's own\n");

        app(HostFiles::class)->addAdminProvider($provider, $providers);

        expect(File::get($provider))->toBe("<?php\n// the site's own\n")
            ->and(File::get($providers))->toContain('HataAdminServiceProvider::class');
    });

    it('prints a by-hand line for an unrecognized providers file', function () {
        $providers = hostFile('bootstrap/providers.php', fn (string $source) => str_replace('];', "    // custom\n];", $source));
        $provider = $this->hostDirectory.'/HataAdminServiceProvider.php';
        $before = File::get($providers);

        $result = app(HostFiles::class)->addAdminProvider($provider, $providers);

        expect($result->status)->toBe(HostFileStatus::Unrecognized)
            ->and($result->lines)->toContain('App\\Providers\\HataAdminServiceProvider::class')
            ->and(File::get($providers))->toBe($before);
    });
});
