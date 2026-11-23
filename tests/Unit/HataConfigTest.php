<?php

use Hata\Support\Config\HataConfig;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('fills nested keys a published file lacks', function () {
    $merged = HataConfig::merge(
        ['media' => ['s3' => ['bucket' => null, 'region' => 'x'], 'disk' => 'public']],
        ['media' => []],
    );

    expect($merged['media'])->toHaveKey('s3')
        ->and($merged['media']['s3'])->toHaveKey('bucket')
        ->and(HataConfig::merge(['media' => ['s3' => ['bucket' => null, 'region' => 'x']]], ['media' => ['s3' => ['region' => 'y']]])['media']['s3'])
        ->toBe(['bucket' => null, 'region' => 'y']);
});

it('replaces a list instead of merging it by index', function () {
    $types = ['A', 'B', 'C', 'D'];

    expect(HataConfig::merge(['content' => ['types' => $types]], ['content' => ['types' => ['A']]])['content']['types'])->toBe(['A']);
});

it('replaces the interface locales as a whole so a site can remove one', function () {
    $defaults = ['admin' => ['interface_locales' => ['en' => 'English', 'uk' => 'Українська'], 'timezone' => 'UTC']];

    expect(HataConfig::merge($defaults, ['admin' => ['interface_locales' => ['en' => 'English']]]))
        ->toBe(['admin' => ['interface_locales' => ['en' => 'English'], 'timezone' => 'UTC']]);
});

it('skips a cached config that has hata and builds one that lacks it', function () {
    $cached = new class(sys_get_temp_dir()) extends Application
    {
        public function configurationIsCached(): bool
        {
            return true;
        }
    };

    $has = new Repository(['hata' => ['marker' => true]]);
    HataConfig::apply($cached, $has);

    $lacks = new Repository([]);
    HataConfig::apply($cached, $lacks);

    expect($has->get('hata'))->toBe(['marker' => true])
        ->and($lacks->get('hata.content.trash_days'))->toBe(30)
        ->and($lacks->get('hata.admin.interface_locales'))->toHaveKey('uk');
});
