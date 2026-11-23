<?php

use Hata\Models\Role;

it('uses imagick when the extension is loaded, otherwise gd', function () {
    expect(config('images.default'))->toBe(extension_loaded('imagick') ? 'imagick' : 'gd');
});

it('registers the media permissions and gives them to editors', function () {
    seedRolesAndPermissions();

    expect(Role::findByName('editor')->permissions->pluck('name')->all())
        ->toContain('media.view', 'media.upload', 'media.edit', 'media.delete');
});

it('raises the livewire upload limit to the largest media limit', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toBe(['required', 'file', 'max:30720']);
});
