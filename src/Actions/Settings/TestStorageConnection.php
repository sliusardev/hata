<?php

namespace App\Actions\Settings;

use Aws\Exception\AwsException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type Step 'credentials'|'bucket'|'region'|'write'|'read'|'list'|'delete'|'endpoint'
 *
 * Checks an S3-compatible storage with a throwaway disk: writes, reads, lists and deletes a small object,
 * and names the step that failed. The secret is only passed to the disk, never logged or returned.
 */
class TestStorageConnection
{
    private const array CREDENTIAL_CODES = [
        'InvalidAccessKeyId', 'SignatureDoesNotMatch', 'InvalidToken', 'ExpiredToken', 'TokenRefreshRequired',
        'InvalidSecurity', 'AccountProblem',
    ];

    private const array REGION_CODES = ['AuthorizationHeaderMalformed', 'PermanentRedirect', 'IllegalLocationConstraintException'];

    private const array BUCKET_CODES = ['NoSuchBucket', 'InvalidBucketName'];

    /**
     * @param  array<string, mixed>  $config  S3 disk options: endpoint, region, bucket, key, secret, use_path_style_endpoint, root.
     * @return array{ok: bool, step?: Step, message?: string}
     */
    public function handle(array $config): array
    {
        if (blank($config['bucket'] ?? null)) {
            return $this->failed('bucket');
        }

        if (blank($config['region'] ?? null)) {
            return $this->failed('region');
        }

        if (blank($config['key'] ?? null) || blank($config['secret'] ?? null)) {
            return $this->failed('credentials');
        }

        $name = 'hata-connection-test-'.Str::lower((string) Str::ulid()).'.txt';
        $content = 'hata '.Str::random(16);
        $written = false;
        $failedStep = null;
        $step = 'write';
        $disk = null;

        try {
            $disk = $this->disk($config);

            $disk->put($name, $content);
            $written = true;

            $step = 'read';
            if ($disk->get($name) !== $content) {
                $failedStep = 'read';
            }

            if ($failedStep === null) {
                $step = 'list';
                foreach ($disk->getDriver()->listContents('', false) as $item) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            $failedStep = $this->stepFor($exception, $step);
        }

        // Whatever happened after the write, try to leave no test object behind.
        if ($written && $disk !== null) {
            try {
                $disk->delete($name);
            } catch (Throwable $exception) {
                $failedStep ??= $this->stepFor($exception, 'delete');
            }
        }

        return $failedStep === null ? ['ok' => true] : $this->failed($failedStep);
    }

    /**
     * The throwaway disk. A seam: tests replace it, so they never reach the network.
     *
     * @param  array<string, mixed>  $config
     */
    protected function disk(array $config): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::build([
            ...$config,
            'driver' => 's3',
            'throw' => true,
            'report' => false,
            'url' => null,
            // A wrong endpoint must fail in seconds, not hang the request.
            'http' => ['connect_timeout' => 5, 'timeout' => 15],
        ]);
    }

    /**
     * The credential, bucket or endpoint problem behind the failure if it is one, else the step that was running.
     *
     * @param  Step  $running
     * @return Step
     */
    private function stepFor(Throwable $exception, string $running): string
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                $code = $current->getAwsErrorCode();

                if (in_array($code, self::CREDENTIAL_CODES, true)) {
                    return 'credentials';
                }

                if (in_array($code, self::REGION_CODES, true)) {
                    return 'region';
                }

                if (in_array($code, self::BUCKET_CODES, true)) {
                    return 'bucket';
                }

                if ($code === null && $current->getResponse() === null) {
                    return 'endpoint';
                }
            }
        }

        return $running;
    }

    /**
     * @param  Step  $step
     * @return array{ok: false, step: Step, message: string}
     */
    private function failed(string $step): array
    {
        return ['ok' => false, 'step' => $step, 'message' => __("site_settings.media.storage.errors.{$step}")];
    }
}
