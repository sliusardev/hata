<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->originalBase = $this->app->basePath();
    $this->originalApp = $this->app->path();
    $this->root = sys_get_temp_dir().'/make-widget-'.Str::random(12);
    File::ensureDirectoryExists($this->root.'/app');

    $this->app->setBasePath($this->root);
    $this->app->useAppPath($this->root.'/app');
});

afterEach(function () {
    $this->app->setBasePath($this->originalBase);
    $this->app->useAppPath($this->originalApp);
    File::deleteDirectory($this->root);
});

test('it writes the widget class and its view and prints the line to register', function () {
    $this->artisan('make:dashboard-widget', ['name' => 'RecentOrders'])
        ->expectsOutputToContain("Admin::dashboard()->add(new DashboardWidget('recent-orders', \\App\\Livewire\\Widgets\\RecentOrdersWidget::class, width: 'third'));")
        ->expectsOutputToContain('did not edit')
        ->assertSuccessful();

    $class = $this->root.'/app/Livewire/Widgets/RecentOrdersWidget.php';
    $view = $this->root.'/resources/views/livewire/widgets/recent-orders.blade.php';

    expect($class)->toBeFile()
        ->and($view)->toBeFile();

    $code = file_get_contents($class);

    expect($code)->toContain('namespace App\Livewire\Widgets;')
        ->and($code)->toContain('class RecentOrdersWidget extends Widget')
        ->and($code)->toContain("__('Recent orders')")
        ->and($code)->toContain("view('livewire.widgets.recent-orders')");

    exec('php -l '.escapeshellarg($class).' 2>&1', $output, $status);
    expect($status)->toBe(0);
});

test('it does not double the Widget suffix and takes the width', function () {
    $this->artisan('make:dashboard-widget', ['name' => 'OrdersWidget', '--width' => 'full'])
        ->expectsOutputToContain("new DashboardWidget('orders', \\App\\Livewire\\Widgets\\OrdersWidget::class, width: 'full')")
        ->assertSuccessful();

    expect($this->root.'/app/Livewire/Widgets/OrdersWidget.php')->toBeFile();
});

test('it refuses to overwrite and rejects a bad width', function () {
    $this->artisan('make:dashboard-widget', ['name' => 'Orders'])->assertSuccessful();
    $path = $this->root.'/app/Livewire/Widgets/OrdersWidget.php';
    file_put_contents($path, 'mine');

    $this->artisan('make:dashboard-widget', ['name' => 'Orders'])->assertFailed();
    expect(file_get_contents($path))->toBe('mine');

    $this->artisan('make:dashboard-widget', ['name' => 'Other', '--width' => 'huge'])->assertFailed();
    expect($this->root.'/app/Livewire/Widgets/OtherWidget.php')->not->toBeFile();
});
