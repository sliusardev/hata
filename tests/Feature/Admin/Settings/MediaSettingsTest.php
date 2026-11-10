<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\Media\ServerCapabilities;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('admin')->create());
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mediaSettingsInput(array $overrides = []): array
{
    return [
        'form.media_optimize' => true,
        'form.media_max_side' => '2560',
        'form.media_quality' => '70',
        'form.media_format' => 'original',
        'form.media_strip_exif' => false,
        'form.media_client_resize' => false,
        'form.media_keep_originals' => false,
        'form.media_max_image_mb' => '40',
        'form.media_max_document_mb' => '25',
        ...$overrides,
    ];
}

function fakeServerCap(?int $bytes): void
{
    $capabilities = Mockery::mock(ServerCapabilities::class)->makePartial();
    $capabilities->shouldReceive('uploadCapBytes')->andReturn($bytes);
    app()->instance(ServerCapabilities::class, $capabilities);
}

it('shows the media tab', function () {
    fakeServerCap(64 * 1024 * 1024);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->assertSet('tab', 'media')
        ->assertSeeHtml('data-test="media-quality-value">80<')
        ->assertSee('64 MB');
});

it('saves the media settings and applies them to config', function () {
    fakeServerCap(null);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set(mediaSettingsInput())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.settings.edit', ['tab' => 'media']));

    expect(config('media.optimize.max_side'))->toBe(2560)
        ->and(config('media.optimize.quality'))->toBe(70)
        ->and(config('media.optimize.format'))->toBe('original')
        ->and(config('media.optimize.strip_exif'))->toBeFalse()
        ->and(config('media.optimize.client_resize'))->toBeFalse()
        ->and(config('media.optimize.keep_originals'))->toBeFalse()
        ->and(config('media.optimize.enabled'))->toBeTrue()
        ->and(config('media.limits.image_mb'))->toBe(40)
        ->and(config('media.limits.document_mb'))->toBe(25);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set(mediaSettingsInput(['form.media_max_side' => 'none']))
        ->call('save')
        ->assertHasNoErrors();

    expect(config('media.optimize.max_side'))->toBeNull();

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->assertSet('form.media_max_side', 'none');
});

it('refuses a limit above the server cap', function () {
    fakeServerCap(10 * 1024 * 1024);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set(mediaSettingsInput(['form.media_max_image_mb' => '11', 'form.media_max_document_mb' => '5']))
        ->call('save')
        ->assertHasErrors(['form.media_max_image_mb'])
        ->assertHasNoErrors(['form.media_max_document_mb']);

    expect(Setting::query()->where('key', 'media_max_image_mb')->exists())->toBeFalse();
});

it('refuses an out-of-range quality and an unknown format', function () {
    fakeServerCap(null);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set(mediaSettingsInput(['form.media_quality' => '10', 'form.media_format' => 'avif']))
        ->call('save')
        ->assertHasErrors(['form.media_quality', 'form.media_format']);
});

it('keeps other tabs untouched', function () {
    fakeServerCap(null);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set(mediaSettingsInput())
        ->set('form.site_name', 'Changed')
        ->call('save')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'site_name')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'media_quality')->exists())->toBeTrue();
});
