<?php

use App\Actions\Media\OptimizeImage;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Support\Media\OptimizationRun;
use App\Support\Media\OptimizationRunStore;
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
        ->assertSeeHtml('x-text="quality">80<')
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

it('lets untouched limits pass on a server with a low cap', function () {
    fakeServerCap(2 * 1024 * 1024);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set('form.media_quality', '65')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('media.optimize.quality'))->toBe(65);
});

it('checks a changed limit against a low cap', function () {
    fakeServerCap(2 * 1024 * 1024);

    Livewire::withQueryParams(['tab' => 'media'])
        ->test('pages::admin.settings.edit')
        ->set('form.media_max_image_mb', '40')
        ->call('save')
        ->assertHasErrors(['form.media_max_image_mb'])
        ->set('form.media_max_image_mb', '1')
        ->call('save')
        ->assertHasNoErrors();

    expect(config('media.limits.image_mb'))->toBe(1);
});

describe('optimize existing card', function () {
    beforeEach(fn () => fakeServerCap(null));

    it('shows the candidate count and the estimated saving', function () {
        Media::factory()->image()->create(['size' => 1_048_576, 'optimized_with' => null]);
        Media::factory()->image()->create(['size' => 1_048_576, 'optimized_with' => 'webp:1920:50:exif0']);
        Media::factory()->image()->create(['size' => 1_048_576, 'optimized_with' => app(OptimizeImage::class)->fingerprint()]);

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertSeeHtml('data-test="optimize-existing-card"')
            ->assertSeeHtml('data-test="optimize-existing-count">2<')
            ->assertSee('1.1 MB')
            ->assertSeeHtml(route('admin.media.optimize'));
    });

    it('says all images match when there are no candidates', function () {
        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertSee(__('site_settings.media.existing_none'))
            ->assertDontSeeHtml('data-test="optimize-existing-count"');
    });

    it('shows the progress of a running run instead of the estimate', function () {
        Media::factory()->image()->create(['optimized_with' => null]);
        app(OptimizationRunStore::class)->save(new OptimizationRun(
            status: 'running',
            fingerprint: app(OptimizeImage::class)->fingerprint(),
            cursor: 0,
            total: 5,
            counts: ['optimized' => 2],
            bytesBefore: 0,
            bytesAfter: 0,
            startedAt: now()->toImmutable(),
        ));

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertSeeHtml('data-test="optimize-existing-progress"')
            ->assertSee(__('media.optimize.progress', ['processed' => 2, 'total' => 5]))
            ->assertDontSeeHtml('data-test="optimize-existing-count"');
    });

    it('shows no counts while optimization is stored as off', function () {
        config(['media.optimize.enabled' => false]);
        Media::factory()->image()->create(['optimized_with' => null]);

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->set('form.media_optimize', true)
            ->assertSeeHtml('data-test="optimize-existing-unsaved"')
            ->assertDontSeeHtml('data-test="optimize-existing-count"')
            ->assertDontSeeHtml(route('admin.media.optimize'));
    });

    it('shows a paused run with its status', function () {
        Media::factory()->image()->create(['optimized_with' => null]);
        app(OptimizationRunStore::class)->save(new OptimizationRun(
            status: 'paused',
            fingerprint: app(OptimizeImage::class)->fingerprint(),
            cursor: 0,
            total: 4,
            counts: ['optimized' => 1],
            bytesBefore: 0,
            bytesAfter: 0,
            startedAt: now()->toImmutable(),
        ));

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertSeeHtml('data-test="optimize-existing-progress"')
            ->assertSee(__('media.optimize.status.paused'))
            ->assertSee(__('media.optimize.progress', ['processed' => 1, 'total' => 4]))
            ->assertSeeHtml(route('admin.media.optimize'));
    });

    it('ignores a stale run and shows the estimate', function () {
        Media::factory()->image()->create(['optimized_with' => null]);
        app(OptimizationRunStore::class)->save(new OptimizationRun(
            status: 'running',
            fingerprint: 'webp:1:1:old',
            cursor: 0,
            total: 9,
            counts: ['optimized' => 3],
            bytesBefore: 0,
            bytesAfter: 0,
            startedAt: now()->toImmutable(),
        ));

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertDontSeeHtml('data-test="optimize-existing-progress"')
            ->assertSeeHtml('data-test="optimize-existing-count">1<');
    });

    it('is hidden without media.edit', function () {
        Media::factory()->image()->create(['optimized_with' => null]);
        $this->actingAs(User::factory()->withPermissions(['admin.access', 'settings.view', 'settings.edit'])->create());

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->assertDontSeeHtml('data-test="optimize-existing-card"');
    });

    it('is hidden while optimization is off', function () {
        Media::factory()->image()->create(['optimized_with' => null]);

        Livewire::withQueryParams(['tab' => 'media'])
            ->test('pages::admin.settings.edit')
            ->set('form.media_optimize', false)
            ->assertDontSeeHtml('data-test="optimize-existing-card"');
    });
});
