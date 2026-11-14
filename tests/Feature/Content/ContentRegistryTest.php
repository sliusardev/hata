<?php

use App\Support\Content\ContentTypes;
use App\Support\Content\Fields\Gallery;
use App\Support\Content\Types\PagesType;
use App\Support\Permissions\PermissionRegistry;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fixtures\ProductsType;

beforeEach(function () {
    $this->types = app(ContentTypes::class);
    $this->types->register(new ProductsType);
    $this->products = $this->types->get('products');
});

test('pages is registered as a hierarchical type with no base and no fields', function () {
    $pages = $this->types->get('pages');

    expect($pages)->toBeInstanceOf(PagesType::class)
        ->and($pages->hierarchical())->toBeTrue()
        ->and($pages->base('en'))->toBeNull()
        ->and($pages->fields())->toBe([])
        ->and($pages->perPage())->toBe(20)
        ->and($pages->permission('edit'))->toBe('pages.edit')
        ->and($pages->label())->toBe('Page')
        ->and($pages->pluralLabel())->toBe('Pages');
});

test('the route pattern matches registered keys only', function () {
    $pattern = '#^(?:'.(new ContentTypes)->register(new PagesType)->pattern().')$#';

    expect(preg_match($pattern, 'pages'))->toBe(1)
        ->and(preg_match($pattern, 'page'))->toBe(0)
        ->and(preg_match($pattern, 'pagesx'))->toBe(0)
        ->and(preg_match('#^(?:'.(new ContentTypes)->pattern().')$#', 'pages'))->toBe(0);
});

test('an unknown key gives null or throws', function () {
    expect($this->types->find('nope'))->toBeNull()
        ->and(fn () => $this->types->get('nope'))->toThrow(InvalidArgumentException::class);
});

test('the four permissions of pages exist after a sync and the editor has them', function () {
    seedRolesAndPermissions();

    $names = ['pages.view', 'pages.create', 'pages.edit', 'pages.delete'];

    expect(Permission::whereIn('name', $names)->count())->toBe(4)
        ->and(Role::findByName('editor')->hasAllPermissions($names))->toBeTrue()
        ->and(Role::findByName('author')->hasAnyPermission($names))->toBeFalse()
        ->and(app(PermissionRegistry::class)->groups()['pages'])->toBe($names);
});

test('a type registered after the providers booted still gets its four permissions', function () {
    seedRolesAndPermissions();

    $names = ['products.view', 'products.create', 'products.edit', 'products.delete'];

    expect(app(PermissionRegistry::class)->groups()['products'])->toBe($names)
        ->and(Permission::whereIn('name', $names)->count())->toBe(4);
});

test('every field kind round-trips between the form and storage', function () {
    $cases = [
        'subtitle' => ['  Hi ', 'Hi', 'Hi'],
        'summary' => ['Short', 'Short', 'Short'],
        'details' => ['<p>x</p>', '<p>x</p>', '<p>x</p>'],
        'price' => ['12.5', 12.5, 12.5],
        'released' => ['2026-11-14', '2026-11-14', '2026-11-14'],
        'featured' => [true, true, true],
        'size' => ['l', 'l', 'l'],
        'cover' => ['7', 7, 7],
        'photos' => [['3', '5'], [3, 5], [3, 5]],
    ];

    foreach ($cases as $name => [$form, $stored, $back]) {
        $field = $this->products->field($name);

        expect($field->toStorage($form))->toBe($stored, $name)
            ->and($field->toForm($stored))->toBe($back, $name)
            ->and($field->rules())->not->toBeEmpty($name);
    }
});

test('empty form values are stored as null, a toggle as false', function () {
    $empty = fn (string $name) => $this->products->field($name)->toStorage('');

    expect($empty('subtitle'))->toBeNull()
        ->and($empty('price'))->toBeNull()
        ->and($empty('released'))->toBeNull()
        ->and($empty('size'))->toBeNull()
        ->and($empty('cover'))->toBeNull()
        ->and($this->products->field('featured')->toStorage(null))->toBeFalse()
        ->and($this->products->field('photos')->toStorage(null))->toBe([])
        ->and($this->products->field('price')->toForm(null))->toBe('');
});

test('a malformed date is stored as null, never an exception', function () {
    expect($this->products->field('released')->toStorage('2026-13-45'))->toBeNull()
        ->and($this->products->field('released')->toStorage('not a date'))->toBeNull()
        ->and($this->products->field('released')->toStorage('2026-02-03'))->toBe('2026-02-03');
});

test('rules reflect required and the select options', function () {
    expect($this->products->field('price')->rules())->toContain('required')
        ->and($this->products->field('subtitle')->rules())->toContain('nullable');

    $validator = validator(['size' => 'xl'], ['size' => $this->products->field('size')->rules()]);

    expect($validator->fails())->toBeTrue();
});

test('shared and local fields are split by the shared flag', function () {
    expect(array_map(fn ($f) => $f->name, $this->products->sharedFields()))->toBe(['price'])
        ->and($this->products->localFields())->toHaveCount(8);
});

test('media ids come from media and gallery fields only', function () {
    expect($this->products->field('photos')->mediaIds([3, 5]))->toBe([3, 5])
        ->and($this->products->field('cover')->mediaIds(9))->toBe([9])
        ->and($this->products->field('cover')->mediaIds(null))->toBe([])
        ->and($this->products->field('subtitle')->mediaIds('3'))->toBe([])
        ->and(Gallery::make('g')->mediaIds('nope'))->toBe([]);
});

test('every field names a view', function () {
    foreach ($this->products->fields() as $field) {
        expect($field->view())->toBeString()->not->toBeEmpty();
    }
});
