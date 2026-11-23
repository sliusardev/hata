<?php

use App\Models\User;
use Hata\Hata;
use Hata\HataServiceProvider;
use Hata\Support\Admin\AdminAssets;
use Hata\Support\Assets\HataAssets;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(Hata::path('hot'));
    File::deleteDirectory(public_path('vendor/shop'));
});

it('renders hata tags from its own manifest without touching the host vite', function () {
    $html = (string) HataAssets::tags(['resources/css/app.css', 'resources/js/app.js']);

    expect($html)
        ->toContain('/vendor/hata/assets/app-')
        ->toContain('.css')
        ->toContain('.js')
        ->not->toContain('/build/');

    // the host's own Vite keeps its build directory and is never reconfigured
    expect(app(Vite::class)->manifestHash())->toBeNull();
});

it('uses the hot file while the package dev server runs', function () {
    File::put(Hata::path('hot'), 'http://localhost:5199');

    expect((string) HataAssets::tags(['resources/css/app.css']))
        ->toContain('http://localhost:5199/@vite/client')
        ->toContain('http://localhost:5199/resources/css/app.css');
});

it('points static files at the published directory', function () {
    expect(HataAssets::url('favicon.ico'))->toEndWith('/vendor/hata/favicon.ico');
});

it('keeps the fonts and the favicons in the built directory', function () {
    expect(Hata::path('dist/favicon.ico'))->toBeFile()
        ->and(Hata::path('dist/favicon.svg'))->toBeFile()
        ->and(Hata::path('dist/apple-touch-icon.png'))->toBeFile()
        ->and(Hata::path('dist/fonts-manifest.json'))->toBeFile();
});

it('builds dynamic chunks that resolve under the published directory', function () {
    $manifest = json_decode(file_get_contents(Hata::path('dist/.vite/manifest.json')), true);
    $entries = collect($manifest)->filter(fn (array $chunk): bool => $chunk['isEntry'] ?? false)->keys()->all();

    expect($entries)->toEqualCanonicalizing([
        'resources/css/app.css', 'resources/css/site.css', 'resources/js/app.js', 'resources/js/passkeys.js',
    ]);

    $app = file_get_contents(Hata::path('dist/'.$manifest['resources/js/app.js']['file']));

    expect($app)->toContain('/vendor/hata/')
        ->and(collect($manifest)->filter(fn (array $chunk): bool => $chunk['isDynamicEntry'] ?? false)->keys()->all())
        ->toContain('resources/js/rich-text.js', 'node_modules/cropperjs/dist/cropper.js');
});

it('appends module assets after hata\'s in the admin head', function () {
    File::ensureDirectoryExists(public_path('vendor/shop'));
    File::put(public_path('vendor/shop/manifest.json'), json_encode([
        'resources/css/shop.css' => ['file' => 'assets/shop-abc123.css', 'src' => 'resources/css/shop.css', 'isEntry' => true],
    ]));

    app(AdminAssets::class)->add('vendor/shop', ['resources/css/shop.css']);
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

    $hata = strpos($html, '/vendor/hata/assets/app-');
    $shop = strpos($html, '/vendor/shop/assets/shop-abc123.css');

    expect($hata)->not->toBeFalse()->and($shop)->not->toBeFalse()->and($shop)->toBeGreaterThan($hata);
});

it('publishes dist under laravel-assets and hata-assets', function () {
    foreach (['laravel-assets', 'hata-assets'] as $tag) {
        $paths = HataServiceProvider::pathsToPublish(HataServiceProvider::class, $tag);

        expect($paths)->toHaveKey(Hata::path('dist'))
            ->and($paths[Hata::path('dist')])->toBe(public_path('vendor/hata'));
    }
});
