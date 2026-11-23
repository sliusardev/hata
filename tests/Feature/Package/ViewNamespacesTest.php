<?php

use App\Models\User;
use Hata\Hata;
use Hata\HataServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Symfony\Component\Finder\SplFileInfo;

it('renders an admin page through the hata livewire namespace', function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    // The page is `hata::admin.users.index`, in the layout `hata::layouts.app` (the sidebar's logout button).
    $this->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(__('hata::users.index.title'))
        ->assertSee('data-test="logout-button"', escape: false);

    expect(app('livewire.finder')->resolveSingleFileComponentPath('hata::admin.users.index'))
        ->toBe(Hata::path('resources/views/livewire/admin/users/⚡index.blade.php'));
});

it('renders hata\'s anonymous components and layouts under the hata prefix', function () {
    expect(View::exists('hata::layouts.app'))->toBeTrue()
        ->and(View::exists('hata::auth.login'))->toBeTrue()
        ->and(View::exists('hata::components.confirm-modal'))->toBeTrue()
        ->and(View::exists('hata::welcome'))->toBeTrue();

    $this->get(route('login'))->assertOk()->assertSee('name="password"', escape: false);
});

it('adds nothing to the host\'s own view paths', function () {
    $intoHata = collect((array) config('livewire.component_namespaces', []))
        ->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, Hata::path('resources')));

    expect(View::getFinder()->getPaths())->not->toContain(Hata::path('resources/views'))
        ->and($intoHata->all())->toBe([])
        ->and(collect(Blade::getAnonymousComponentPaths())->pluck('path')
            ->filter(fn (string $path): bool => str_starts_with($path, Hata::path('resources/views')))
            ->reject(fn (string $path): bool => $path === Hata::path('resources/views/flux'))
            ->all())->toBe([]);
});

it('resolves no unprefixed component to hata\'s views', function (string $name) {
    $compiler = new ComponentTagCompiler(Blade::getClassComponentAliases(), Blade::getClassComponentNamespaces(), app('blade.compiler'));

    $resolved = rescue(fn () => $compiler->componentClass($name), null, false);

    expect($resolved === null || ! str_contains((string) View::getFinder()->find($resolved), Hata::path('resources/views')))->toBeTrue();
})->with(['welcome', 'dashboard', 'layouts.app', 'layouts.auth', 'auth.login', 'partials.head', 'confirm-modal']);

it('gives every layout a hata component alias', function () {
    $layouts = collect(File::allFiles(Hata::path('resources/views/layouts')))
        ->map(fn (SplFileInfo $file): string => str_replace(['/', '.blade.php'], ['.', ''], $file->getRelativePathname()))
        ->sort()->values()->all();

    expect($layouts)->toEqualCanonicalizing(HataServiceProvider::LAYOUTS)
        ->and(Blade::render('<x-hata::layouts.focus title="Focus">Body</x-hata::layouts.focus>'))->toContain('Body');
});

it('lets a site\'s override of a layout win for its component too', function () {
    $override = storage_path('framework/testing/hata-override');
    File::ensureDirectoryExists($override.'/layouts');
    File::put($override.'/layouts/focus.blade.php', '<div>overridden focus {{ $slot }}</div>');
    View::prependNamespace('hata', $override);   // what loadViewsFrom() does for resources/views/vendor/hata

    try {
        expect(Blade::render('<x-hata::layouts.focus>Body</x-hata::layouts.focus>'))->toContain('overridden focus Body');
    } finally {
        File::deleteDirectory($override);
    }
});

it('does not leave unprefixed hata views behind', function () {
    $name = '[\'"]([^\'"]+)[\'"]';
    $patterns = [
        "/(?<!::)\\bview\\(\\s*{$name}/",
        "/@(?:include|includeIf|extends|each|livewire)\\(\\s*{$name}/",
        "/@include(?:When|Unless)\\([^,]+,\\s*{$name}/",
        "/@includeFirst\\(\\s*\\[\\s*{$name}/",
        '/<\\/?x-([a-zA-Z0-9:._-]+)/',
        '/<x-dynamic-component[^>]*\\bcomponent="\'?([^"\'$][^"\']*)/',
        '/<livewire:([a-zA-Z0-9:._-]+)/',
        "/Route::(?:livewire|view)\\(\\s*[\'\"][^\'\"]*[\'\"],\\s*{$name}/",
        "/Layout\\(\\s*{$name}/",
    ];

    $files = collect(File::allFiles(Hata::path('resources/views')))
        ->reject(fn (SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'flux/'))
        ->merge(File::allFiles(Hata::path('src')))
        ->merge(File::allFiles(Hata::path('routes')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'));

    $unprefixed = $files->flatMap(function (SplFileInfo $file) use ($patterns): array {
        $source = File::get($file->getPathname());
        $found = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $view) {
                if (! in_array($view, ['slot', 'dynamic-component'], true) && ! str_starts_with($view, 'slot:') && ! str_starts_with($view, 'hata::')) {
                    $found[] = $file->getRelativePathname().': '.$view;
                }
            }
        }

        return $found;
    })->unique()->values()->all();

    expect($unprefixed)->toBe([]);
});
