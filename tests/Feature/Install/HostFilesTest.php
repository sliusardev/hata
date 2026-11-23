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
    it('adds view:clear after the skeleton\'s asset publish and keeps the rest of the file', function () {
        $path = hostFile('composer.json');
        $skeleton = File::get($path);

        expectEditedOnceInto($path, fn (string $file) => app(HostFiles::class)->ensureAssetsPublishScript($file), str_replace(
            "            \"@php artisan vendor:publish --tag=laravel-assets --ansi --force\"\n",
            "            \"@php artisan vendor:publish --tag=laravel-assets --ansi --force\",\n            \"@php artisan view:clear --ansi\"\n",
            $skeleton,
        ));
    });

    it('adds both commands to a composer.json without post-update-cmd', function () {
        $path = hostFile('composer.json', function (string $source) {
            $data = json_decode($source, true);
            unset($data['scripts']['post-update-cmd']);

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        });

        expect(app(HostFiles::class)->ensureAssetsPublishScript($path)->status)->toBe(HostFileStatus::Changed);

        $data = json_decode(File::get($path), true);

        expect($data['scripts']['post-update-cmd'])->toBe([
            '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
            '@php artisan view:clear --ansi',
        ])
            ->and($data['scripts']['post-autoload-dump'])->toHaveCount(2)
            ->and(File::get($path))->toContain("\n    \"scripts\": {\n        \"post-update-cmd\": [\n");
    });

    it('leaves a composer.json it cannot read alone', function () {
        $path = hostFile('composer.json', fn (string $source) => str_replace('"scripts": {', '"scripts": {,', $source));

        expectLeftUntouched($path, fn (string $file) => app(HostFiles::class)->ensureAssetsPublishScript($file), '"@php artisan view:clear --ansi"');
    });
});
