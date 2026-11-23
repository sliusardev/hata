<?php

namespace Hata\Support\Content;

use Hata\Hata;
use Hata\Models\Entry;
use Hata\Rules\EntrySlug;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Localization\ReservedSlugs;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Writes a new content type: its class, its entry in `config/hata.php` → `content.types`, and its lines in every
 * language's `content.php` and `permissions.php` (English text in each, to be translated).
 *
 * The config and language files are edited as text, by the shape Pint gives them (a top-level `'section' => [`
 * closed by `],` at the same indent; the type list is the `types` section inside the `content` one). A file that has another shape is left alone,
 * and so is one that no longer loads with the new lines; the lines are returned to be added by hand.
 */
class ContentTypeGenerator
{
    private const string TYPES_NAMESPACE = 'Hata\\Support\\Content\\Types';

    private readonly string $typesPath;

    private readonly string $langPath;

    private readonly string $configFile;

    public function __construct(
        private readonly ContentTypes $types,
        private readonly ReservedSlugs $reserved,
        private readonly LanguageCatalog $catalog,
        private readonly PermissionRegistry $permissions,
        ?string $typesPath = null,
        ?string $langPath = null,
        ?string $configFile = null,
    ) {
        $this->typesPath = $typesPath ?? Hata::path('src/Support/Content/Types');
        $this->langPath = $langPath ?? Hata::path('resources/lang');
        $this->configFile = $configFile ?? Hata::path('config/hata.php');
    }

    /**
     * @param  string  $name  The plural name of the type, such as `Posts` or `CaseStudies`.
     * @param  string|null  $key  The stored type, admin address segment and permission group; the name in kebab case by default.
     * @param  string|null  $base  The first segment of the public address; the key by default.
     *
     * @throws InvalidArgumentException when the name, key, base or icon is unusable or already taken
     */
    public function generate(string $name, ?string $key = null, ?string $base = null, bool $hierarchical = false, string $icon = 'document-text'): GeneratedContentType
    {
        $studly = Str::studly(preg_replace('/Type\z/', '', trim($name)) ?? '');

        if (preg_match('/\A[A-Z][A-Za-z0-9]*\z/', $studly) !== 1) {
            throw new InvalidArgumentException("The name [{$name}] cannot be a class name. Use a plural name such as Posts.");
        }

        $words = $this->words($studly);
        $key ??= implode('-', $words);
        $base ??= str_replace('_', '-', $key);
        $class = $studly.'Type';
        $classFile = $this->typesPath.'/'.$class.'.php';

        $this->assertUsable($key, $base, $icon, $classFile);

        $plural = Str::plural(Str::singular(implode(' ', $words)));
        $edits = $this->edits($key, $class, Str::singular($plural), $plural, $hierarchical);

        $originals = [];
        $updated = [];
        $manual = [];

        try {
            File::ensureDirectoryExists($this->typesPath);
            File::put($classFile, $this->classSource($class, $key, $base, $hierarchical, $icon));

            foreach ($edits as $edit) {
                if ($this->apply($edit, $originals)) {
                    $updated[] = $edit['file'];
                } else {
                    $manual[] = ['file' => $edit['file'], 'lines' => $edit['lines']];
                }
            }
        } catch (Throwable $exception) {
            foreach ($originals as $file => $original) {
                @file_put_contents($file, $original);
            }

            File::delete($classFile);

            throw $exception;
        }

        /** @var class-string $fqcn */
        $fqcn = self::TYPES_NAMESPACE.'\\'.$class;

        return new GeneratedContentType($key, $fqcn, $classFile, $updated, $manual);
    }

    /**
     * The lowercase words of a class name, split where a capital follows a lowercase letter or a digit, so an
     * acronym stays one word (`CaseStudies` → case, studies; `FAQs` → faqs).
     *
     * @return list<string>
     */
    private function words(string $studly): array
    {
        return array_map(Str::lower(...), preg_split('/(?<=[a-z0-9])(?=[A-Z])/', $studly) ?: [$studly]);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function assertUsable(string $key, string $base, string $icon, string $classFile): void
    {
        if (preg_match('/\A[a-z0-9_-]+\z/', $key) !== 1) {
            throw new InvalidArgumentException("The key [{$key}] may contain only lowercase Latin letters, digits, hyphens and underscores.");
        }

        if ($this->types->find($key) !== null) {
            throw new InvalidArgumentException("The content type [{$key}] is already registered.");
        }

        if (array_key_exists($key, $this->permissions->groups())) {
            throw new InvalidArgumentException("The key [{$key}] is already a permission group; the type would share its permissions.");
        }

        if (File::exists($classFile)) {
            throw new InvalidArgumentException("The file [{$classFile}] already exists.");
        }

        if (preg_match(EntrySlug::FORMAT, $base) !== 1) {
            throw new InvalidArgumentException("The base [{$base}] must be one address segment of lowercase Latin letters, digits and single hyphens.");
        }

        if ($this->reserved->contains($base)) {
            throw new InvalidArgumentException("The base [{$base}] is reserved (a language code, a service address or a public file).");
        }

        foreach ($this->types->all() as $type) {
            foreach ($this->catalog->codes() as $locale) {
                if ($type->base($locale) === $base) {
                    throw new InvalidArgumentException("The base [{$base}] is already used by the content type [{$type->key()}].");
                }
            }
        }

        if ($this->isAddressOfRecord($base)) {
            throw new InvalidArgumentException("The base [{$base}] is the address of an existing record, which the type would hide. Choose another base.");
        }

        if (preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $icon) !== 1 || ! $this->hasIcon($icon)) {
            throw new InvalidArgumentException("The icon [{$icon}] is not an icon of the admin, such as document-text.");
        }
    }

    /**
     * Whether Flux has the icon, among its own or the project's (`resources/views/flux/icon`). The sidebar renders
     * a type's icon on every admin page, and a missing one fails them all.
     */
    private function hasIcon(string $icon): bool
    {
        foreach (Blade::getAnonymousComponentPaths() as $components) {
            if ($components['prefix'] === 'flux' && File::isFile("{$components['path']}/icon/{$icon}.blade.php")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a record (trashed ones included, their paths stay reserved) of a type with no base sits at the
     * top of the address under this name: the router resolves a type's base first, so the record and everything
     * under it would stop opening. A database that cannot be asked (not migrated yet) has no such record.
     */
    private function isAddressOfRecord(string $base): bool
    {
        $atRoot = [];

        foreach ($this->types->all() as $type) {
            foreach ($this->catalog->codes() as $locale) {
                if ($type->base($locale) === null) {
                    $atRoot[] = $type->key();

                    break;
                }
            }
        }

        try {
            return $atRoot !== [] && Entry::withTrashed()->whereIn('type', $atRoot)->where('path', $base)->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * The edits to the config and language files: where the lines go and how to tell that they arrived.
     *
     * @return list<array{file: string, lines: string, inserts: list<array{section: string, lines: string, parent?: string}>, import: string|null, expect: string, contains: string|null}>
     */
    private function edits(string $key, string $class, string $singular, string $plural, bool $hierarchical): array
    {
        $fqcn = self::TYPES_NAMESPACE.'\\'.$class;
        $configLine = "            {$class}::class,\n";

        $edits = [[
            'file' => $this->configFile,
            'lines' => "use {$fqcn};\n\n'types' => [\n{$configLine}],\n",
            'inserts' => [['section' => 'types', 'lines' => $configLine, 'parent' => 'content']],
            'import' => $fqcn,
            'expect' => 'content.types',
            'contains' => $fqcn,
        ]];

        $content = $this->contentLines($key, $singular, $plural, $hierarchical);
        $group = '        '.$this->pair($key, Str::ucfirst($plural))."\n";
        $items = $this->permissionLines($key, $plural);

        foreach (File::isDirectory($this->langPath) ? File::directories($this->langPath) : [] as $directory) {
            $edits[] = [
                'file' => $directory.'/content.php',
                'lines' => "'types' => [\n{$content}],\n",
                'inserts' => [['section' => 'types', 'lines' => $content]],
                'import' => null,
                'expect' => "types.{$key}.label",
                'contains' => null,
            ];

            $edits[] = [
                'file' => $directory.'/permissions.php',
                'lines' => "'groups' => [\n{$group}],\n'items' => [\n{$items}],\n",
                'inserts' => [['section' => 'groups', 'lines' => $group], ['section' => 'items', 'lines' => $items]],
                'import' => null,
                'expect' => "items.{$key}.delete",
                'contains' => null,
            ];
        }

        return $edits;
    }

    private function contentLines(string $key, string $singular, string $plural, bool $hierarchical): string
    {
        $description = 'The '.$plural.' of your site'.($hierarchical ? ', arranged in a tree.' : '.');

        return "        '{$key}' => [\n"
            .'            '.$this->pair('label', Str::ucfirst($singular))."\n"
            .'            '.$this->pair('plural', Str::ucfirst($plural))."\n"
            .'            '.$this->pair('description', $description)."\n"
            .'            '.$this->pair('new', 'New '.$singular)."\n"
            .'            '.$this->pair('create', 'Create '.$singular)."\n"
            ."        ],\n";
    }

    private function permissionLines(string $key, string $plural): string
    {
        return "        '{$key}' => [\n"
            .'            '.$this->pair('view', 'View '.$plural)."\n"
            .'            '.$this->pair('create', 'Create '.$plural)."\n"
            .'            '.$this->pair('edit', 'Edit and publish '.$plural)."\n"
            .'            '.$this->pair('delete', 'Move '.$plural.' to the trash and delete them')."\n"
            ."        ],\n";
    }

    private function pair(string $key, string $value): string
    {
        return "'{$key}' => '".addcslashes($value, "'\\")."',";
    }

    /**
     * Writes one edit, keeping the file's text in `$originals` first. True also when the file already holds the
     * lines (left from an earlier run or added by hand; it is not touched). False when the file is missing, has
     * another shape, or no longer loads with the lines (it is then put back as it was).
     *
     * @param  array{file: string, lines: string, inserts: list<array{section: string, lines: string, parent?: string}>, import: string|null, expect: string, contains: string|null}  $edit
     * @param  array<string, string>  $originals
     */
    private function apply(array $edit, array &$originals): bool
    {
        if (! File::isFile($edit['file'])) {
            return false;
        }

        if ($this->arrived($edit)) {
            return true;
        }

        $original = File::get($edit['file']);
        $source = $original;

        foreach ($edit['inserts'] as $insert) {
            $source = $this->insertIntoSection($source, $insert['section'], $insert['lines'], $insert['parent'] ?? null);

            if ($source === null) {
                return false;
            }
        }

        if ($edit['import'] !== null) {
            $source = $this->addImport($source, $edit['import']);

            if ($source === null) {
                return false;
            }
        }

        $originals[$edit['file']] = $original;
        File::put($edit['file'], $source);

        if (! $this->arrived($edit)) {
            File::put($edit['file'], $original);

            return false;
        }

        return true;
    }

    /**
     * Whether the file loads and holds what the edit adds. Reads the file each time it is asked.
     *
     * @phpstan-impure
     *
     * @param  array{file: string, expect: string, contains: string|null}  $edit
     */
    private function arrived(array $edit): bool
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($edit['file'], true);
        }

        try {
            $data = require $edit['file'];
        } catch (Throwable) {
            return false;
        }

        if (! is_array($data) || ! Arr::has($data, $edit['expect'])) {
            return false;
        }

        return $edit['contains'] === null || in_array($edit['contains'], (array) Arr::get($data, $edit['expect']), true);
    }

    /**
     * Adds the lines at the end of an array section (a top-level one, or one inside the top-level `$parent`), or
     * returns null when the file has no such section in the expected shape.
     */
    private function insertIntoSection(string $source, string $section, string $lines, ?string $parent = null): ?string
    {
        $indent = $parent === null ? '    ' : '        ';
        $body = '/^( {'.strlen($indent).'}\''.preg_quote($section, '/').'\' => \[\n(?:.*\n)*?)( {'.strlen($indent).'}\],\n)/m';

        if ($parent !== null) {
            $outer = '/^    \''.preg_quote($parent, '/').'\' => \[\n(?:.*\n)*?^    \],\n/m';

            if (preg_match($outer, $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
                return null;
            }

            $inner = preg_replace_callback($body, fn (array $found): string => $found[1].$lines.$found[2], $match[0][0], 1, $count);

            return $count === 1 ? substr_replace($source, $inner, $match[0][1], strlen($match[0][0])) : null;
        }

        $result = preg_replace_callback($body, fn (array $found): string => $found[1].$lines.$found[2], $source, 1, $count);

        return $count === 1 ? $result : null;
    }

    /**
     * Adds a `use` line to the file's block of imports, kept in the order Pint sorts them, or after the opening
     * tag when it has none. Null when another import already has the class's short name (the file would not
     * compile) or the imports are not one block of plain `use` lines.
     */
    private function addImport(string $source, string $fqcn): ?string
    {
        $line = "use {$fqcn};\n";

        if (preg_match_all('/^use [^;\n]+;\n/m', $source, $matches) === 0) {
            return preg_replace('/\A<\?php\n\n?/', "<?php\n\n{$line}\n", $source, 1);
        }

        if (in_array($line, $matches[0], true)) {
            return $source;
        }

        $block = implode('', $matches[0]);
        $short = preg_quote(class_basename($fqcn), '/');

        if (! str_contains($source, $block) || preg_match('/^use [^;\n]*(\\\\| as )'.$short.';$/m', $block) === 1) {
            return null;
        }

        $lines = [...$matches[0], $line];
        usort($lines, fn (string $first, string $second): int => strcasecmp(rtrim($first, ";\n"), rtrim($second, ";\n")));

        return str_replace($block, implode('', $lines), $source);
    }

    private function classSource(string $class, string $key, string $base, bool $hierarchical, string $icon): string
    {
        $tree = $hierarchical ? 'true' : 'false';
        $namespace = self::TYPES_NAMESPACE;

        return <<<PHP
            <?php

            namespace {$namespace};

            use Hata\Support\Content\ContentType;
            use Hata\Support\Content\Fields\Field;

            class {$class} extends ContentType
            {
                public function key(): string
                {
                    return '{$key}';
                }

                public function icon(): string
                {
                    return '{$icon}';
                }

                public function hierarchical(): bool
                {
                    return {$tree};
                }

                /**
                 * The first segment of the public address. Return another slug for a language to translate it
                 * (`\$locale === 'uk' ? 'blog' : 'news'`).
                 */
                public function base(string \$locale): ?string
                {
                    return '{$base}';
                }

                /**
                 * The type's extra fields, from `Hata\Support\Content\Fields` (`Text::make('subtitle')`); `->shared()`
                 * keeps one value for every language. Each label is `content.fields.<name>` in the language files.
                 *
                 * @return list<Field>
                 */
                public function fields(): array
                {
                    return [];
                }
            }

            PHP;
    }
}
