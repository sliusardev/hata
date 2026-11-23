<?php

use Hata\Hata;
use Hata\Models\Entry;
use Hata\Support\Content\ContentType;
use Hata\Support\Content\ContentTypeGenerator;
use Hata\Support\Content\ContentTypes;
use Hata\Support\Content\Types\CaseStudiesType;
use Hata\Support\Content\Types\RecipesType;
use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Localization\ReservedSlugs;
use Hata\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Fixtures\ProductsType;

/**
 * The generator works on copies of the project's own config and language files, so a change to their shape
 * that it cannot follow fails here.
 */
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/make-content-type-'.Str::random(12);

    File::ensureDirectoryExists($this->root.'/config');
    File::copy(Hata::path('config/hata.php'), $this->root.'/config/hata.php');

    foreach (['en', 'uk'] as $locale) {
        File::ensureDirectoryExists("{$this->root}/lang/{$locale}");
        File::copy(Hata::path("resources/lang/{$locale}/content.php"), "{$this->root}/lang/{$locale}/content.php");
        File::copy(Hata::path("resources/lang/{$locale}/permissions.php"), "{$this->root}/lang/{$locale}/permissions.php");
    }

    $this->app->bind(ContentTypeGenerator::class, fn ($app): ContentTypeGenerator => new ContentTypeGenerator(
        $app->make(ContentTypes::class),
        $app->make(ReservedSlugs::class),
        $app->make(LanguageCatalog::class),
        $app->make(PermissionRegistry::class),
        $this->root.'/types',
        $this->root.'/lang',
        $this->root.'/config/hata.php',
    ));
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

function generatedFiles(string $root): array
{
    $files = [];

    foreach (File::allFiles($root) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($files);

    return $files;
}

test('it writes a flat type with its config entry and its lines in every language', function () {
    $this->artisan('make:content-type', ['name' => 'Recipes'])
        ->expectsOutputToContain('Content type [recipes] created.')
        ->assertSuccessful();

    require $this->root.'/types/RecipesType.php';
    $type = new RecipesType;

    expect($type)->toBeInstanceOf(ContentType::class)
        ->and($type->key())->toBe('recipes')
        ->and($type->icon())->toBe('document-text')
        ->and($type->hierarchical())->toBeFalse()
        ->and($type->base('en'))->toBe('recipes')
        ->and($type->base('uk'))->toBe('recipes')
        ->and($type->fields())->toBe([])
        ->and((new ContentTypes)->register($type)->find('recipes'))->toBe($type);

    expect((require $this->root.'/config/hata.php')['content']['types'])
        ->toBe([...(require Hata::path('config/hata.php'))['content']['types'], 'Hata\Support\Content\Types\RecipesType']);

    foreach (['en', 'uk'] as $locale) {
        $content = require "{$this->root}/lang/{$locale}/content.php";
        $permissions = require "{$this->root}/lang/{$locale}/permissions.php";

        expect($content['types']['recipes'])->toBe([
            'label' => 'Recipe',
            'plural' => 'Recipes',
            'description' => 'The recipes of your site.',
            'new' => 'New recipe',
            'create' => 'Create recipe',
        ])
            ->and($content['types']['pages'])->toBe((require Hata::path("resources/lang/{$locale}/content.php"))['types']['pages'])
            ->and($permissions['groups']['recipes'])->toBe('Recipes')
            ->and($permissions['items']['recipes'])->toBe([
                'view' => 'View recipes',
                'create' => 'Create recipes',
                'edit' => 'Edit and publish recipes',
                'delete' => 'Move recipes to the trash and delete them',
            ])
            ->and($permissions['items']['pages'])->toBe((require Hata::path("resources/lang/{$locale}/permissions.php"))['items']['pages']);
    }
});

test('it takes the key from a name of several words and applies the options', function () {
    $this->artisan('make:content-type', ['name' => 'CaseStudiesType', '--tree' => true, '--base' => 'cases', '--icon' => 'briefcase'])
        ->assertSuccessful();

    require $this->root.'/types/CaseStudiesType.php';
    $type = new CaseStudiesType;

    expect($type->key())->toBe('case-studies')
        ->and($type->icon())->toBe('briefcase')
        ->and($type->hierarchical())->toBeTrue()
        ->and($type->base('en'))->toBe('cases');

    expect((require $this->root.'/lang/en/content.php')['types']['case-studies'])->toBe([
        'label' => 'Case study',
        'plural' => 'Case studies',
        'description' => 'The case studies of your site, arranged in a tree.',
        'new' => 'New case study',
        'create' => 'Create case study',
    ]);
});

test('it takes the key from the option', function () {
    $this->artisan('make:content-type', ['name' => 'News', '--key' => 'articles'])->assertSuccessful();

    expect(File::get($this->root.'/types/NewsType.php'))->toContain("return 'articles';")
        ->and((require $this->root.'/lang/uk/permissions.php')['groups'])->toHaveKey('articles');
});

test('it refuses an unusable type and changes nothing', function (array $arguments, string $message) {
    app(ContentTypes::class)->register(new ProductsType);
    $before = generatedFiles($this->root);

    $this->artisan('make:content-type', $arguments)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(generatedFiles($this->root))->toBe($before);
})->with([
    'a name that is no class name' => [['name' => '123'], 'cannot be a class name'],
    'a registered key' => [['name' => 'Pages'], 'The content type [pages] is already registered.'],
    'a key with a capital letter' => [['name' => 'Reviews', '--key' => 'Reviews'], 'The key [Reviews] may contain only'],
    'a base of two segments' => [['name' => 'Reviews', '--base' => 'blog/news'], 'The base [blog/news] must be one address segment'],
    'a base that is a service address' => [['name' => 'Reviews', '--base' => 'admin'], 'The base [admin] is reserved'],
    'a base that is a language code' => [['name' => 'Reviews', '--base' => 'de'], 'The base [de] is reserved'],
    'a base of another type' => [['name' => 'Goods', '--base' => 'products'], 'already used by the content type [products]'],
    'a key that is a permission group' => [['name' => 'Users'], 'The key [users] is already a permission group'],
    'an icon that is no icon name' => [['name' => 'Reviews', '--icon' => "x'; evil();"], 'is not an icon of the admin'],
    'an icon the admin does not have' => [['name' => 'Reviews', '--icon' => 'newspapper'], 'is not an icon of the admin'],
]);

test('it keeps an acronym one word and makes the default base of an underscored key a slug', function () {
    $this->artisan('make:content-type', ['name' => 'FAQs'])->assertSuccessful();
    $this->artisan('make:content-type', ['name' => 'Notes', '--key' => 'blog_notes'])->assertSuccessful();

    expect(File::get($this->root.'/types/FAQsType.php'))->toContain("return 'faqs';")
        ->and((require $this->root.'/lang/en/content.php')['types']['faqs']['plural'])->toBe('Faqs')
        ->and(File::get($this->root.'/types/NotesType.php'))->toContain("return 'blog_notes';")->toContain("return 'blog-notes';");
});

test('it keeps the imports of the config in sorted order', function () {
    $this->artisan('make:content-type', ['name' => 'Articles'])->assertSuccessful();

    $config = File::get($this->root.'/config/hata.php');

    preg_match_all('/^use [^;]+;$/m', $config, $imports);
    $sorted = $imports[0];
    sort($sorted);

    expect($imports[0])->toBe($sorted)->toContain('use Hata\\Support\\Content\\Types\\ArticlesType;')
        ->and((require $this->root.'/config/hata.php')['content']['types'])->toBe([...(require Hata::path('config/hata.php'))['content']['types'], 'Hata\Support\Content\Types\ArticlesType']);
});

test('it refuses a base that is the address of an existing page, trashed or not', function (bool $trashed) {
    $page = Entry::factory()->page()->published()->create(['slug' => 'reviews', 'path' => 'reviews']);

    if ($trashed) {
        $page->delete();
    }

    $before = generatedFiles($this->root);

    $this->artisan('make:content-type', ['name' => 'Reviews'])
        ->expectsOutputToContain('The base [reviews] is the address of an existing record')
        ->assertFailed();

    expect(generatedFiles($this->root))->toBe($before);
})->with(['live' => [false], 'trashed' => [true]]);

test('it does not touch a file that already holds the lines of the type', function () {
    $translated = str_replace(
        "    'types' => [\n",
        "    'types' => [\n        'reviews' => ['label' => 'Відгук', 'plural' => 'Відгуки', 'description' => 'Відгуки.', 'new' => 'Новий відгук', 'create' => 'Створити відгук'],\n",
        File::get($this->root.'/lang/uk/content.php'),
    );
    File::put($this->root.'/lang/uk/content.php', $translated);

    $this->artisan('make:content-type', ['name' => 'Reviews'])->assertSuccessful();

    expect(File::get($this->root.'/lang/uk/content.php'))->toBe($translated)
        ->and((require $this->root.'/lang/en/content.php')['types']['reviews']['label'])->toBe('Review');
});

test('it puts every file back when one cannot be written', function () {
    $before = generatedFiles($this->root);
    chmod($this->root.'/lang/uk/permissions.php', 0444);

    $this->artisan('make:content-type', ['name' => 'Reviews'])
        ->expectsOutputToContain('Nothing was changed')
        ->assertFailed();

    expect(generatedFiles($this->root))->toBe($before);
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root may write a read-only file');

test('it refuses to overwrite an existing class file', function () {
    File::ensureDirectoryExists($this->root.'/types');
    File::put($this->root.'/types/ReviewsType.php', '<?php // mine');

    $this->artisan('make:content-type', ['name' => 'Reviews'])
        ->expectsOutputToContain('already exists')
        ->assertFailed();

    expect(File::get($this->root.'/types/ReviewsType.php'))->toBe('<?php // mine');
});

test('it leaves a file of another shape alone and prints its lines to add by hand', function () {
    File::put($this->root.'/lang/uk/content.php', "<?php\n\nreturn ['types' => []];\n");

    $this->artisan('make:content-type', ['name' => 'Reviews'])
        ->expectsOutputToContain('Add these lines by hand')
        ->expectsOutputToContain("'create' => 'Create review',")
        ->assertSuccessful();

    expect(File::get($this->root.'/lang/uk/content.php'))->toBe("<?php\n\nreturn ['types' => []];\n")
        ->and((require $this->root.'/lang/en/content.php')['types'])->toHaveKey('reviews')
        ->and((require $this->root.'/lang/uk/permissions.php')['items'])->toHaveKey('reviews');
});
