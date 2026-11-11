<?php

use App\Actions\Settings\TestStorageConnection;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DirectoryListing;
use League\Flysystem\Filesystem;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;

/**
 * An action whose throwaway disk is the given one, so no test reaches the network.
 */
function connectionTestWith(FilesystemAdapter $disk): TestStorageConnection
{
    return new class($disk) extends TestStorageConnection
    {
        public function __construct(private FilesystemAdapter $fake) {}

        protected function disk(array $config): FilesystemAdapter
        {
            return $this->fake;
        }
    };
}

/**
 * @return array<string, mixed>
 */
function validS3Config(array $overrides = []): array
{
    return [
        'endpoint' => 'http://127.0.0.1:9000',
        'region' => 'us-east-1',
        'bucket' => 'bucket',
        'key' => 'access',
        'secret' => 'topsecret',
        'use_path_style_endpoint' => true,
        ...$overrides,
    ];
}

function s3Failure(string $code, string $message = 'boom'): S3Exception
{
    return new S3Exception($message, new Command('PutObject'), ['code' => $code]);
}

/**
 * A disk where every step works except the ones given as step => exception.
 *
 * @param  array<string, Throwable>  $failures
 */
function diskFailingAt(array $failures): FilesystemAdapter
{
    $listing = new DirectoryListing([]);
    $driver = Mockery::mock(Filesystem::class);
    $driver->shouldReceive('listContents')->andReturnUsing(function () use ($failures, $listing) {
        if (isset($failures['list'])) {
            throw $failures['list'];
        }

        return $listing;
    });

    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('getDriver')->andReturn($driver);
    $written = null;
    $disk->shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use ($failures, &$written) {
        throw_if(isset($failures['write']), $failures['write'] ?? null);
        $written = $contents;

        return true;
    });
    $disk->shouldReceive('get')->andReturnUsing(function (string $path) use ($failures, &$written) {
        throw_if(isset($failures['read']), $failures['read'] ?? null);

        return $written;
    });
    $disk->shouldReceive('delete')->andReturnUsing(function () use ($failures) {
        throw_if(isset($failures['delete']), $failures['delete'] ?? null);

        return true;
    });

    return $disk;
}

it('passes and leaves no object behind on a working disk', function () {
    $disk = Storage::fake('s3-test');

    expect(connectionTestWith($disk)->handle(validS3Config()))->toBe(['ok' => true])
        ->and($disk->allFiles())->toBe([]);
});

it('refuses a config without a bucket or credentials before any request', function (array $override, string $step) {
    $result = connectionTestWith(diskFailingAt([]))->handle(validS3Config($override));

    expect($result['ok'])->toBeFalse()
        ->and($result['step'])->toBe($step)
        ->and($result['message'])->not->toBeEmpty();
})->with([
    'no bucket' => [['bucket' => ''], 'bucket'],
    'no key' => [['key' => ''], 'credentials'],
    'no secret' => [['secret' => null], 'credentials'],
]);

it('maps a failure to the step that explains it', function (string $failingAt, Throwable $exception, string $expected) {
    $result = connectionTestWith(diskFailingAt([$failingAt => $exception]))->handle(validS3Config());

    expect($result['ok'])->toBeFalse()
        ->and($result['step'])->toBe($expected)
        ->and($result['message'])->not->toBeEmpty()
        ->and($result['message'])->not->toContain('topsecret');
})->with([
    'write denied' => ['write', UnableToWriteFile::atLocation('x', 'denied', s3Failure('AccessDenied')), 'write'],
    'read denied' => ['read', UnableToReadFile::fromLocation('x', 'denied', s3Failure('AccessDenied')), 'read'],
    'list denied' => ['list', new RuntimeException('denied', 0, s3Failure('AccessDenied')), 'list'],
    'delete denied' => ['delete', UnableToDeleteFile::atLocation('x', 'denied', s3Failure('AccessDenied')), 'delete'],
    'bad key' => ['write', UnableToWriteFile::atLocation('x', 'bad', s3Failure('InvalidAccessKeyId')), 'credentials'],
    'bad signature' => ['write', UnableToWriteFile::atLocation('x', 'bad', s3Failure('SignatureDoesNotMatch')), 'credentials'],
    'missing bucket' => ['write', UnableToWriteFile::atLocation('x', 'bad', s3Failure('NoSuchBucket')), 'bucket'],
    'unreachable' => ['write', UnableToWriteFile::atLocation('x', 'bad', new S3Exception('cURL error 7: Failed to connect', new Command('PutObject'))), 'endpoint'],
]);
