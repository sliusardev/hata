<?php

use Hata\Support\Admin\AdminPath;

it('accepts the default and a hyphenated path', function (string $path) {
    config(['hata.admin.path' => $path]);

    expect(AdminPath::resolve())->toBe($path);
})->with(['admin', 'cp-7f3k', 'x1']);

it('refuses an invalid admin path', function (mixed $path) {
    config(['hata.admin.path' => $path]);

    expect(fn () => AdminPath::resolve())->toThrow(InvalidArgumentException::class, 'HATA_ADMIN_PATH');
})->with(['', 'a/b', 'Admin', 'en', 'login', 'settings', 'livewire', '-x', null]);
