<?php

use App\Actions\Settings\TestStorageConnection;
use App\Models\Media;
use App\Models\Setting;
use App\Models\User;
use App\Support\Media\MediaDisks;
use App\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    seedRolesAndPermissions();
    $this->actingAs(User::factory()->withRole('admin')->create());
});

/**
 * Replace the connection test with one that records the config it got and returns the given result.
 *
 * @param  array<string, mixed>  $result
 */
function fakeConnectionTest(array $result = ['ok' => true]): object
{
    $fake = new class($result) extends TestStorageConnection
    {
        /** @var list<array<string, mixed>> */
        public array $calls = [];

        /** @param array<string, mixed> $result */
        public function __construct(private array $result) {}

        public function handle(array $config): array
        {
            $this->calls[] = $config;

            return $this->result;
        }
    };

    app()->instance(TestStorageConnection::class, $fake);

    return $fake;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function s3FormInput(array $overrides = []): array
{
    return [
        'form.media_storage' => 's3',
        'form.media_s3_endpoint' => 'http://127.0.0.1:9000',
        'form.media_s3_region' => 'us-east-1',
        'form.media_s3_bucket' => 'media-bucket',
        'form.media_s3_key' => 'access-key',
        'form.media_s3_secret' => 'topsecret',
        'form.media_s3_path_style' => true,
        'form.media_s3_public_url' => 'https://cdn.example.com/',
        'form.media_s3_prefix' => '/prod/',
        ...$overrides,
    ];
}

function storageTab(): Testable
{
    return Livewire::withQueryParams(['tab' => 'media'])->test('pages::admin.settings.edit');
}

it('maps the storage settings onto config and rebuilds the s3 disk', function () {
    config(['filesystems.disks.s3.bucket' => 'old-bucket']);
    expect(Storage::disk('s3')->getConfig()['bucket'])->toBe('old-bucket');

    app(SiteSettings::class)->put([
        'media_storage' => 's3',
        'media_s3_endpoint' => 'http://minio.test:9000',
        'media_s3_region' => 'eu-west-1',
        'media_s3_bucket' => 'new-bucket',
        'media_s3_key' => 'k',
        'media_s3_secret' => 's',
        'media_s3_path_style' => true,
        'media_s3_public_url' => 'https://cdn.test',
        'media_s3_prefix' => 'prod',
    ]);

    expect(config('media.disk'))->toBe('s3')
        ->and(config('filesystems.disks.s3.endpoint'))->toBe('http://minio.test:9000')
        ->and(config('filesystems.disks.s3.region'))->toBe('eu-west-1')
        ->and(config('filesystems.disks.s3.key'))->toBe('k')
        ->and(config('filesystems.disks.s3.secret'))->toBe('s')
        ->and(config('filesystems.disks.s3.use_path_style_endpoint'))->toBeTrue()
        ->and(config('filesystems.disks.s3.url'))->toBe('https://cdn.test')
        ->and(config('filesystems.disks.s3.root'))->toBe('prod')
        ->and(Storage::disk('s3')->getConfig()['bucket'])->toBe('new-bucket');
});

it('stores the s3 settings from the media tab, encrypts the secret and never renders it', function () {
    $test = fakeConnectionTest();

    storageTab()
        ->set(s3FormInput())
        ->call('save')
        ->assertHasNoErrors();

    $stored = Setting::query()->where('key', 'media_s3_secret')->value('value');

    expect($stored)->not->toBe('topsecret')
        ->and(Crypt::decryptString($stored))->toBe('topsecret')
        ->and(config('media.disk'))->toBe('s3')
        ->and(config('filesystems.disks.s3.bucket'))->toBe('media-bucket')
        ->and(config('filesystems.disks.s3.url'))->toBe('https://cdn.example.com')
        ->and(config('filesystems.disks.s3.root'))->toBe('prod')
        ->and($test->calls)->toHaveCount(1)
        ->and($test->calls[0]['secret'])->toBe('topsecret');

    storageTab()
        ->assertSet('form.media_s3_secret', '')
        ->assertSet('form.media_s3_bucket', 'media-bucket')
        ->assertDontSee('topsecret')
        ->assertDontSeeHtml('topsecret');
});

it('keeps the stored secret when the field is left empty and tests with it', function () {
    app(SiteSettings::class)->put(['media_s3_secret' => 'stored-secret', 'media_s3_bucket' => 'b', 'media_s3_key' => 'k']);
    $test = fakeConnectionTest();

    storageTab()
        ->set(s3FormInput(['form.media_s3_secret' => '', 'form.media_s3_bucket' => 'other']))
        ->call('save')
        ->assertHasNoErrors();

    expect(Crypt::decryptString(Setting::query()->where('key', 'media_s3_secret')->value('value')))->toBe('stored-secret')
        ->and($test->calls[0]['secret'])->toBe('stored-secret');
});

it('refuses to save s3 as the storage when the connection test fails, and keeps the old settings', function () {
    fakeConnectionTest(['ok' => false, 'step' => 'bucket', 'message' => 'The bucket was not found.']);

    storageTab()
        ->set(s3FormInput())
        ->call('save')
        ->assertHasErrors('form.media_storage')
        ->assertSee('The bucket was not found.');

    expect(Setting::query()->where('key', 'like', 'media_s3_%')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'media_storage')->exists())->toBeFalse()
        ->and(config('media.disk'))->toBe('local');
});

it('saves the connection fields without a test while the storage stays local', function () {
    $test = fakeConnectionTest(['ok' => false, 'step' => 'endpoint', 'message' => 'x']);

    storageTab()
        ->set(s3FormInput(['form.media_storage' => 'local']))
        ->call('save')
        ->assertHasNoErrors();

    expect($test->calls)->toBe([])
        ->and(config('filesystems.disks.s3.bucket'))->toBe('media-bucket')
        ->and(config('media.disk'))->toBe('local');
});

it('requires a bucket, key and secret for s3', function () {
    fakeConnectionTest();
    config(['filesystems.disks.s3.secret' => null]);

    storageTab()
        ->set(s3FormInput(['form.media_s3_bucket' => '', 'form.media_s3_key' => '', 'form.media_s3_secret' => '']))
        ->call('save')
        ->assertHasErrors(['form.media_s3_bucket', 'form.media_s3_key', 'form.media_s3_secret']);
});

it('tests the connection with the unsaved form and shows the result', function () {
    $test = fakeConnectionTest(['ok' => false, 'step' => 'credentials', 'message' => 'The keys were refused.']);

    storageTab()
        ->set(s3FormInput())
        ->call('testStorageConnection')
        ->assertSee('The keys were refused.')
        ->assertDontSee('topsecret');

    expect($test->calls[0]['bucket'])->toBe('media-bucket')
        ->and(Setting::query()->where('key', 'like', 'media_s3_%')->exists())->toBeFalse();

    fakeConnectionTest();

    storageTab()
        ->set(s3FormInput())
        ->call('testStorageConnection')
        ->assertSee(__('site_settings.media.storage.test_ok'));
});

it('warns before changing the bucket, endpoint, prefix or public url while files are on s3', function (string $field, string $value) {
    fakeConnectionTest();
    app(SiteSettings::class)->put([
        'media_storage' => 's3', 'media_s3_endpoint' => 'http://127.0.0.1:9000', 'media_s3_region' => 'us-east-1',
        'media_s3_bucket' => 'media-bucket', 'media_s3_key' => 'access-key', 'media_s3_secret' => 'topsecret',
        'media_s3_path_style' => true, 'media_s3_public_url' => 'https://cdn.example.com', 'media_s3_prefix' => 'prod',
    ]);
    Media::factory()->create(['disk' => 's3']);

    storageTab()
        ->set(s3FormInput([$field => $value, 'form.media_s3_secret' => '']))
        ->call('save')
        ->assertSet('showS3ChangeModal', true)
        ->assertSee(__('site_settings.media.storage.change_heading'));

    expect(config('filesystems.disks.s3.bucket'))->toBe('media-bucket');

    storageTab()
        ->set(s3FormInput([$field => $value, 'form.media_s3_secret' => '']))
        ->call('save')
        ->call('confirmS3Change')
        ->assertHasNoErrors();

    expect(Setting::query()->where('key', 'media_s3_bucket')->value('value'))
        ->toBe($field === 'form.media_s3_bucket' ? $value : 'media-bucket');
})->with([
    'bucket' => ['form.media_s3_bucket', 'another-bucket'],
    'endpoint' => ['form.media_s3_endpoint', 'http://other:9000'],
    'prefix' => ['form.media_s3_prefix', 'staging'],
    'public url' => ['form.media_s3_public_url', 'https://other.example.com'],
]);

it('does not warn when no file is on s3', function () {
    fakeConnectionTest();
    app(SiteSettings::class)->put(['media_s3_bucket' => 'media-bucket']);

    storageTab()
        ->set(s3FormInput(['form.media_s3_bucket' => 'another']))
        ->call('save')
        ->assertSet('showS3ChangeModal', false);

    expect(config('filesystems.disks.s3.bucket'))->toBe('another');
});

it('refuses to clear the connection while files are on s3', function () {
    fakeConnectionTest();
    app(SiteSettings::class)->put(['media_s3_bucket' => 'media-bucket', 'media_s3_key' => 'k', 'media_s3_secret' => 's']);
    Media::factory()->create(['disk' => 's3']);

    storageTab()
        ->set(s3FormInput(['form.media_storage' => 'local', 'form.media_s3_bucket' => '', 'form.media_s3_key' => '']))
        ->call('save')
        ->assertHasErrors('form.media_s3_bucket');

    expect(config('filesystems.disks.s3.bucket'))->toBe('media-bucket');
});

it('says how many files are on the other disk', function () {
    Media::factory()->count(2)->create(['disk' => 's3']);

    storageTab()->assertSee(trans_choice('site_settings.media.storage.other_disk', 2, ['count' => 2]));
});

it('knows which disks are cloud disks and their public url', function () {
    config(['filesystems.disks.s3.url' => 'https://cdn.example.com/']);

    expect(MediaDisks::isCloud('s3'))->toBeTrue()
        ->and(MediaDisks::isCloud('local'))->toBeFalse()
        ->and(MediaDisks::isCloud('missing'))->toBeFalse()
        ->and(MediaDisks::publicUrl('s3'))->toBe('https://cdn.example.com')
        ->and(MediaDisks::publicUrl('local'))->toBeNull();

    config(['filesystems.disks.s3.url' => '']);

    expect(MediaDisks::publicUrl('s3'))->toBeNull();
});
