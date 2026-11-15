<?php

namespace App\Console\Commands;

use App\Support\Content\ContentTypeGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

#[Signature('make:content-type
    {name : The plural name of the type, such as Posts or CaseStudies}
    {--key= : The stored type, admin address segment and permission group (the name in kebab case by default)}
    {--base= : The first segment of the public address (the key by default)}
    {--tree : Records form a tree, like pages}
    {--icon=document-text : The icon of the type in the admin menu}')]
#[Description('Create a content type: its class, its config entry and its language lines')]
class MakeContentType extends Command
{
    public function handle(ContentTypeGenerator $generator): int
    {
        if ($this->laravel->configurationIsCached()) {
            $this->components->error('The config is cached, so the types of config/content.php may not be the registered ones. Run `php artisan config:clear` first.');

            return self::FAILURE;
        }

        try {
            $type = $generator->generate(
                (string) $this->argument('name'),
                $this->option('key') ?: null,
                $this->option('base') ?: null,
                (bool) $this->option('tree'),
                (string) $this->option('icon'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->components->error('Nothing was changed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Content type [{$type->key}] created.");
        $this->components->twoColumnDetail($this->relative($type->classFile), 'created');

        foreach ($type->updated as $file) {
            $this->components->twoColumnDetail($this->relative($file), 'updated');
        }

        foreach ($type->manual as $manual) {
            $this->components->warn("Could not update {$this->relative($manual['file'])}. Add these lines by hand:");
            $this->line($manual['lines']);
        }

        $this->components->bulletList([
            'Translate the new lines in lang/*/content.php and lang/*/permissions.php (they are in English).',
            "Run `php artisan permissions:sync`, then give the {$type->key}.* permissions to roles (config/permissions.php → roles, or the Roles page).",
            'If the config or the routes are cached, rebuild them (`php artisan optimize`).',
        ]);

        return self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path;
    }
}
