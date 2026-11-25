<?php

namespace Hata\Console\Commands;

use Hata\Hata;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('make:dashboard-widget
    {name : The name of the widget, such as Orders or RecentOrders}
    {--width=third : The width on the dashboard: third, half or full}')]
#[Description('Create a dashboard widget: its Livewire class and its view')]
class MakeDashboardWidget extends Command
{
    private const WIDTHS = ['third', 'half', 'full'];

    public function handle(): int
    {
        $width = (string) $this->option('width');

        if (! in_array($width, self::WIDTHS, true)) {
            $this->components->error('The width must be one of: '.implode(', ', self::WIDTHS).'.');

            return self::FAILURE;
        }

        $name = Str::studly((string) $this->argument('name'));
        $name = Str::endsWith($name, 'Widget') ? Str::beforeLast($name, 'Widget') : $name;

        if ($name === '' || ! preg_match('/^[A-Z][A-Za-z0-9]*\z/', $name)) {
            $this->components->error('The name must be letters and digits, such as Orders or RecentOrders.');

            return self::FAILURE;
        }

        $kebab = Str::kebab($name);
        $class = $name.'Widget';
        $classFile = app_path("Livewire/Widgets/{$class}.php");
        $viewFile = resource_path("views/livewire/widgets/{$kebab}.blade.php");

        foreach ([$classFile, $viewFile] as $file) {
            if (file_exists($file)) {
                $this->components->error("{$this->relative($file)} already exists. Nothing was changed.");

                return self::FAILURE;
            }
        }

        $replace = [
            '{{ class }}' => $class,
            '{{ title }}' => ucfirst(strtolower(Str::headline($name))),
            '{{ view }}' => $kebab,
        ];

        $this->write($classFile, strtr($this->stub('dashboard-widget.stub'), $replace));
        $this->write($viewFile, strtr($this->stub('dashboard-widget-view.stub'), $replace));

        $this->components->info("Dashboard widget [{$kebab}] created.");
        $this->components->twoColumnDetail($this->relative($classFile), 'created');
        $this->components->twoColumnDetail($this->relative($viewFile), 'created');

        $this->components->warn('Hata did not edit your provider. Add this to HataAdminServiceProvider::boot():');
        $this->line("    Admin::dashboard()->add(new DashboardWidget('{$kebab}', \\App\\Livewire\\Widgets\\{$class}::class, width: '{$width}'));");
        $this->line('    use Hata\Admin;  use Hata\Support\Admin\DashboardWidget;');

        return self::SUCCESS;
    }

    private function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }

    private function stub(string $name): string
    {
        return (string) file_get_contents(Hata::path('stubs/'.$name));
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path;
    }
}
