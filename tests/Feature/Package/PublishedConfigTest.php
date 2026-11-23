<?php

use Hata\Hata;
use Hata\HataServiceProvider;
use Hata\Support\Config\HataConfig;
use Illuminate\Support\ServiceProvider;

it('applies a published hata config over the defaults', function () {
    $defaultReserved = config('hata.content.reserved_slugs');

    $published = tempnam(sys_get_temp_dir(), 'hata');
    file_put_contents($published, '<?php return ["content" => ["trash_days" => 7]];');

    config(['hata' => require $published]);
    HataConfig::apply(app(), app('config'));
    unlink($published);

    expect(config('hata.content.trash_days'))->toBe(7)
        ->and(config('hata.content.reserved_slugs'))->toBe($defaultReserved)
        ->and(config('hata.admin.interface_locales'))->toHaveKey('uk')
        ->and(config('hata.languages'))->toHaveKey('uk')
        ->and(config('hata.permissions.groups'))->toHaveKey('users');
});

it('publishes the hata config under its tag', function () {
    expect(ServiceProvider::pathsToPublish(HataServiceProvider::class, 'hata-config'))
        ->toBe([Hata::path('config/hata.php') => config_path('hata.php')]);
});

it('keeps the language catalogue and permission data out of the published file', function () {
    $published = require Hata::path('config/hata.php');

    expect($published)->not->toHaveKeys(['languages', 'permissions']);
});
