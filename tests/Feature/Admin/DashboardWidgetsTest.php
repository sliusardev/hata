<?php

use App\Models\User;
use Hata\Admin;
use Hata\Support\Admin\DashboardWidget;
use Hata\Support\Admin\DashboardWidgets;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Fixtures\Widgets\BrokenWidget;
use Tests\Fixtures\Widgets\HelloWidget;

beforeEach(function () {
    seedRolesAndPermissions();
    HelloWidget::register();
});

it('renders the widgets the user may see, in order and width', function () {
    Admin::dashboard()
        ->add(new DashboardWidget('late', 'test-hello-widget', 'users.view', 'third', 50))
        ->add(new DashboardWidget('hello', 'test-hello-widget', width: 'half', order: 5));
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $html = $this->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($html)->toContain('md:col-span-3')
        ->and(substr_count($html, 'data-test="dashboard-widget"'))->toBe(1)
        ->and($html)->not->toContain('md:col-span-2');
});

it('orders the widgets by their order', function () {
    Admin::dashboard()
        ->add(new DashboardWidget('b', 'test-hello-widget', order: 20, width: 'full'))
        ->add(new DashboardWidget('a', 'test-hello-widget', order: 10, width: 'third'));
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $html = $this->get(route('admin.dashboard'))->getContent();

    expect(strpos($html, 'md:col-span-2'))->toBeLessThan(strpos($html, 'md:col-span-6'));
});

it('shows the empty state when no widget is visible', function () {
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $this->get(route('admin.dashboard'))->assertOk()
        ->assertSee(__('hata::dashboard.empty.heading'))
        ->assertDontSee('data-test="dashboard-grid"', false);
});

it('loads a widget lazily and bundles all of them in one request', function () {
    Admin::dashboard()
        ->add(new DashboardWidget('one', 'test-hello-widget'))
        ->add(new DashboardWidget('two', 'test-hello-widget'));
    $this->actingAs(User::factory()->withPermissions(['admin.access'])->create());

    $html = $this->get(route('admin.dashboard'))->getContent();

    expect($html)->not->toContain('Hello-Body')->toContain('Hello-Title')
        ->and($html)->toContain('animate-pulse')
        ->and(substr_count($html, 'lazyIsolated&quot;:false'))->toBe(2)
        ->and(substr_count($html, 'data-test="dashboard-widget"'))->toBe(2);
});

it('renders the title and the body of a widget', function () {
    Livewire::test(HelloWidget::class)->assertSee('Hello-Title')->assertSee('Hello-Body FROM-THE-COMPONENT');
});

it('surfaces a broken widget in testing', function () {
    try {
        Livewire::test(BrokenWidget::class);
    } catch (Throwable $exception) {
        expect($exception->getMessage())->toContain('the data source is down');

        return;
    }

    $this->fail('The broken widget did not throw.');
});

it('shows an error card for a broken widget in production', function () {
    $this->app['env'] = 'production';
    Log::spy();

    Livewire::test(BrokenWidget::class)
        ->assertSee(__('hata::dashboard.widget_failed'))
        ->assertSee('Broken-Title');

    Log::shouldHaveReceived('warning')->once();
});

it('treats a widget whose component does not exist as a registration mistake', function () {
    Admin::dashboard()->add(new DashboardWidget('ghost', 'no-such-widget'));

    app(DashboardWidgets::class)->all();
})->throws(LogicException::class, 'ghost');

it('rejects an unknown width', function () {
    Admin::dashboard()->add(new DashboardWidget('wide', 'test-hello-widget', width: 'huge'));

    app(DashboardWidgets::class)->all();
})->throws(LogicException::class, 'width');
