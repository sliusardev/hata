<?php

use App\Models\User;
use Hata\Hata;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Symfony\Component\Finder\SplFileInfo;

it('renders an admin page through the hata livewire namespace', function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('super-admin')->create());

    // The page is `hata::admin.users.index`, in the layout `hata::layouts.app` (the sidebar's logout button).
    $this->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee(__('users.index.title'))
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
    expect(View::getFinder()->getPaths())->not->toContain(Hata::path('resources/views'))
        ->and(config('livewire.component_namespaces'))->not->toContain(Hata::path('resources/views/pages'))
        ->and(config('livewire.component_namespaces'))->not->toContain(Hata::path('resources/views/layouts'));
});

it('does not leave unprefixed hata views behind', function () {
    $patterns = [
        "/(?<!::)\\bview\\(\\s*'([^']+)'/",
        "/@(?:include|extends)\\(\\s*'([^']+)'/",
        '/<\\/?x-([a-zA-Z0-9:._-]+)/',
        '/<livewire:([a-zA-Z0-9:._-]+)/',
        "/Route::(?:livewire|view)\\(\\s*'[^']*',\\s*'([^']+)'/",
        "/Layout\\(\\s*'([^']+)'/",
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

            foreach ($matches[1] as $name) {
                if ($name !== 'slot' && ! str_starts_with($name, 'slot:') && ! str_starts_with($name, 'hata::')) {
                    $found[] = $file->getRelativePathname().': '.$name;
                }
            }
        }

        return $found;
    })->unique()->values()->all();

    expect($unprefixed)->toBe([]);
});
