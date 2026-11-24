<?php

namespace Hata\Livewire\Admin\Settings;

use Closure;
use Flux\Flux;
use Hata\Actions\Media\CreateStorageLink;
use Hata\Actions\Media\MoveMediaToDisk;
use Hata\Actions\Media\OptimizeImage;
use Hata\Actions\Settings\TestStorageConnection;
use Hata\Actions\Settings\UpdateSiteSettings;
use Hata\Exceptions\StorageLinkException;
use Hata\Exceptions\StorageSettingsException;
use Hata\Models\Media;
use Hata\Support\Media\DirectServing;
use Hata\Support\Media\MediaDisks;
use Hata\Support\Media\OptimizationRun;
use Hata\Support\Media\OptimizationRunStore;
use Hata\Support\Media\ServerCapabilities;
use Hata\Support\Settings\SiteSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The media section of the site settings: optimization, upload limits and where files are stored (this server or an
 * S3-compatible service). The view (`hata::admin.settings.sections.media`) holds the markup.
 *
 * Storage fields are stored only when changed, so an untouched one keeps following `.env`. A change to where existing
 * S3 files are looked up asks for confirmation first, and a changed connection is tested by the action that stores it.
 *
 * @property-read bool $hasS3Files
 */
abstract class MediaSection extends SettingsSectionPage
{
    /**
     * The maximum side of an optimized image, in pixels, or "none".
     */
    public const array MEDIA_MAX_SIDES = ['1920', '2560', '3840', 'none'];

    /**
     * Asks for confirmation when the bucket, endpoint, prefix or public URL change while files are on s3.
     */
    public bool $showS3ChangeModal = false;

    /**
     * The last "Test connection" result, null until one runs or after a connection field changes.
     *
     * @var array{ok: bool, message: string}|null
     */
    public ?array $storageTest = null;

    /**
     * The count behind filesOnOtherDisk(), kept in the component's state: no update of the section can change it (the
     * saved storage changes only on save, which reloads the page, and moves run on another page).
     */
    #[Locked]
    public ?int $filesOnOtherDiskCount = null;

    private bool $s3ChangeConfirmed = false;

    public static function key(): string
    {
        return 'media';
    }

    protected function fields(): array
    {
        return ['media_optimize', 'media_max_side', 'media_quality', 'media_format', 'media_strip_exif', 'media_client_resize', 'media_keep_originals', 'media_max_image_mb', 'media_max_document_mb', ...UpdateSiteSettings::STORAGE_FIELDS];
    }

    protected function secretFields(): array
    {
        return ['media_s3_secret'];
    }

    protected function rules(): array
    {
        return [
            'values.media_optimize' => ['boolean'],
            'values.media_max_side' => ['required', Rule::in(self::MEDIA_MAX_SIDES)],
            'values.media_quality' => ['required', 'integer', 'between:50,100'],
            'values.media_format' => ['required', Rule::in(['webp', 'original'])],
            'values.media_strip_exif' => ['boolean'],
            'values.media_client_resize' => ['boolean'],
            'values.media_keep_originals' => ['boolean'],
            'values.media_storage' => ['required', Rule::in(['public', 's3'])],
            'values.media_s3_endpoint' => ['nullable', 'url:http,https', 'max:255'],
            'values.media_s3_region' => ['nullable', 'required_if:values.media_storage,s3', 'string', 'max:64'],
            'values.media_s3_bucket' => ['nullable', 'required_if:values.media_storage,s3', 'string', 'max:255'],
            'values.media_s3_key' => ['nullable', 'required_if:values.media_storage,s3', 'string', 'max:255'],
            'values.media_s3_secret' => [
                'nullable',
                Rule::requiredIf(fn (): bool => ($this->values['media_storage'] ?? null) === 's3' && blank(config('filesystems.disks.s3.secret'))),
                'string',
                'max:1000',
            ],
            'values.media_s3_path_style' => ['boolean'],
            'values.media_s3_public_url' => ['nullable', 'url:http,https', 'max:255'],
            'values.media_s3_prefix' => ['nullable', 'string', 'max:255'],
            'values.media_max_image_mb' => ['required', 'integer', 'between:1,512', $this->withinServerCap('hata.media.limits.image_mb')],
            'values.media_max_document_mb' => ['required', 'integer', 'between:1,512', $this->withinServerCap('hata.media.limits.document_mb')],
        ];
    }

    protected function load(): array
    {
        $maxSide = config('hata.media.optimize.max_side');

        return [
            'media_optimize' => (bool) config('hata.media.optimize.enabled'),
            'media_max_side' => is_int($maxSide) ? (string) $maxSide : 'none',
            'media_quality' => (string) config('hata.media.optimize.quality'),
            'media_format' => (string) config('hata.media.optimize.format'),
            'media_strip_exif' => (bool) config('hata.media.optimize.strip_exif'),
            'media_client_resize' => (bool) config('hata.media.optimize.client_resize'),
            'media_keep_originals' => (bool) config('hata.media.optimize.keep_originals'),
            'media_max_image_mb' => (string) config('hata.media.limits.image_mb'),
            'media_max_document_mb' => (string) config('hata.media.limits.document_mb'),
            'media_storage' => config('hata.media.disk') === 's3' ? 's3' : 'public',
            'media_s3_endpoint' => (string) config('filesystems.disks.s3.endpoint'),
            'media_s3_region' => (string) config('filesystems.disks.s3.region'),
            'media_s3_bucket' => (string) config('filesystems.disks.s3.bucket'),
            'media_s3_key' => (string) config('filesystems.disks.s3.key'),
            'media_s3_secret' => '',
            'media_s3_path_style' => (bool) config('filesystems.disks.s3.use_path_style_endpoint'),
            'media_s3_public_url' => (string) config('filesystems.disks.s3.url'),
            'media_s3_prefix' => (string) config('filesystems.disks.s3.root'),
        ];
    }

    protected function prepare(array $validated): array
    {
        foreach (['media_optimize', 'media_strip_exif', 'media_client_resize', 'media_keep_originals', 'media_s3_path_style'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = (bool) $validated[$field];
            }
        }

        if (array_key_exists('media_max_side', $validated)) {
            $validated['media_max_side'] = $validated['media_max_side'] === 'none' ? 0 : (int) $validated['media_max_side'];
        }

        foreach (['media_quality', 'media_max_image_mb', 'media_max_document_mb'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = (int) $validated[$field];
            }
        }

        foreach (['media_s3_public_url', 'media_s3_prefix'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = filled($validated[$field]) ? trim((string) $validated[$field], '/') : null;
            }
        }

        return parent::prepare($validated);
    }

    /**
     * Saving ends the private `MEDIA_DISK=local` mode (decision 52): the form loads it as "This server", so that
     * choice would look untouched; it is stored as `public` instead, which never runs the connection test.
     */
    protected function changed(): array
    {
        $changed = parent::changed();

        if (config('hata.media.disk') === 'local') {
            $changed = array_values(array_unique([...$changed, 'media_storage']));
        }

        return $changed;
    }

    protected function persist(array $changed): void
    {
        try {
            app(UpdateSiteSettings::class)->handle(Auth::user(), $changed);
        } catch (StorageSettingsException $exception) {
            throw ValidationException::withMessages(["values.{$exception->field}" => $exception->getMessage()]);
        }
    }

    /**
     * A test result describes the values it ran with, so it goes away when they change.
     */
    public function updated(string $name): void
    {
        if (str_starts_with($name, 'values.media_s3_') || $name === 'values.media_storage') {
            $this->storageTest = null;
        }
    }

    /**
     * Asks for confirmation before a save that moves where existing s3 files are looked up.
     */
    protected function beforePersist(array $changed): bool
    {
        if (! $this->s3ChangeConfirmed && $this->movesS3Files()) {
            $this->showS3ChangeModal = true;

            return false;
        }

        return true;
    }

    /**
     * Save after the admin confirmed that the existing s3 files are already at the new location.
     */
    public function confirmS3Change(): void
    {
        $this->authorizeSection();

        $this->showS3ChangeModal = false;
        $this->s3ChangeConfirmed = true;

        $this->save();
    }

    /**
     * Whether the form changes where existing s3 files are looked up (bucket, endpoint, prefix, public URL)
     * while some media rows are on s3.
     */
    private function movesS3Files(): bool
    {
        $changed = false;

        foreach (['media_s3_bucket', 'media_s3_endpoint', 'media_s3_prefix', 'media_s3_public_url'] as $field) {
            $changed = $changed || trim((string) ($this->values[$field] ?? ''), '/') !== trim((string) ($this->saved[$field] ?? ''), '/');
        }

        // A cleared bucket or key is refused by the action; no point asking first.
        if (blank($this->values['media_s3_bucket'] ?? null) || blank($this->values['media_s3_key'] ?? null)) {
            return false;
        }

        // The connection settings belong to the `s3` disk.
        return $changed && $this->hasS3Files;
    }

    /**
     * Whether any media row is on the `s3` disk, the one the connection settings configure.
     */
    #[Computed]
    public function hasS3Files(): bool
    {
        return Media::query()->where('disk', 's3')->exists();
    }

    /**
     * Number of media files the move page would offer for the saved storage: files not where an upload would put them.
     * Counted once per page load, when the section first renders. None while the storage is not one the move page
     * can move to (the private `local` mode set in `.env`).
     */
    #[Computed]
    public function filesOnOtherDisk(): int
    {
        $disk = (string) config('hata.media.disk');

        if (! in_array($disk, MoveMediaToDisk::TARGETS, true)) {
            return 0;
        }

        return $this->filesOnOtherDiskCount ??= MoveMediaToDisk::candidates($disk)->count();
    }

    /**
     * Whether the saved local storage is served as static files, one of the DirectServing states.
     */
    #[Computed]
    public function directState(): string
    {
        return app(DirectServing::class)->state();
    }

    #[Computed]
    public function avifSupported(): bool
    {
        return app(ServerCapabilities::class)->supportsAvif();
    }

    /**
     * The server upload cap as text, null when PHP sets none.
     */
    #[Computed]
    public function serverCap(): ?string
    {
        $bytes = app(ServerCapabilities::class)->uploadCapBytes();

        return $bytes === null ? null : Number::fileSize($bytes);
    }

    /**
     * The "optimize existing files" card: the run in progress, or how many stored images don't match the saved settings
     * and a rough saving (85% of the size of never-optimized files, 20% of the rest). Two SQL sums, no rows loaded.
     *
     * @return array{enabled: bool, run: OptimizationRun|null, count: int, saving: string}
     */
    #[Computed]
    public function optimizationCard(): array
    {
        if (! config('hata.media.optimize.enabled')) {
            return ['enabled' => false, 'run' => null, 'count' => 0, 'saving' => ''];
        }

        $fingerprint = app(OptimizeImage::class)->fingerprint();
        $run = app(OptimizationRunStore::class)->get();

        if ($run !== null && in_array($run->status, ['running', 'paused'], true) && ! $run->isStale($fingerprint)) {
            return ['enabled' => true, 'run' => $run, 'count' => 0, 'saving' => ''];
        }

        $totals = Media::query()
            ->optimizationCandidates($fingerprint)
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when optimized_with is null then size else 0 end), 0) as fresh_size')
            ->selectRaw('coalesce(sum(case when optimized_with is null then 0 else size end), 0) as old_size')
            ->first();

        $saving = (int) round((float) $totals->fresh_size * 0.85 + (float) $totals->old_size * 0.20);

        return ['enabled' => true, 'run' => null, 'count' => (int) $totals->total, 'saving' => Media::formatBytes($saving)];
    }

    #[Computed]
    public function hasStoredS3Secret(): bool
    {
        return app(SiteSettings::class)->has('media_s3_secret');
    }

    /**
     * A secret key that comes from the server configuration (.env) rather than from a saved setting.
     */
    #[Computed]
    public function hasEnvS3Secret(): bool
    {
        return ! $this->hasStoredS3Secret() && filled(config('filesystems.disks.s3.secret'));
    }

    /**
     * Create the `public/storage` link, then probe it in the browser so the status line settles right away.
     */
    public function createStorageLink(CreateStorageLink $createStorageLink): void
    {
        $this->authorizeSection();

        try {
            $createStorageLink->handle(Auth::user());
        } catch (StorageLinkException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());

            return;
        }

        unset($this->directState);
        Flux::toast(variant: 'success', text: __('hata::site_settings.media.storage.direct.link_created'));
        // The status line refreshes itself on the `media-direct-checked` event once the result is recorded.
        $this->js('window.checkMediaDirect?.(true)');
    }

    /**
     * Try the storage with the values in the form (saved or not).
     */
    public function testStorageConnection(TestStorageConnection $connectionTest): void
    {
        $this->authorizeSection();

        $limiterKey = 'storage-test:'.Auth::id();

        if (RateLimiter::tooManyAttempts($limiterKey, 10)) {
            $this->storageTest = [
                'ok' => false,
                'message' => __('hata::site_settings.media.storage.errors.rate_limited', ['seconds' => RateLimiter::availableIn($limiterKey)]),
            ];

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $result = $connectionTest->handle(MediaDisks::connectionConfig([
            'media_s3_endpoint' => $this->values['media_s3_endpoint'] ?? null,
            'media_s3_region' => $this->values['media_s3_region'] ?? null,
            'media_s3_bucket' => $this->values['media_s3_bucket'] ?? null,
            'media_s3_key' => $this->values['media_s3_key'] ?? null,
            'media_s3_secret' => $this->values['media_s3_secret'] ?? null,
            'media_s3_path_style' => $this->values['media_s3_path_style'] ?? null,
            'media_s3_prefix' => $this->values['media_s3_prefix'] ?? null,
        ]));

        $this->storageTest = [
            'ok' => $result['ok'],
            'message' => $result['ok'] ? __('hata::site_settings.media.storage.test_ok') : ($result['message'] ?? ''),
        ];
    }

    /**
     * Refuses a limit above what PHP accepts (upload_max_filesize, post_max_size), but only when
     * the value differs from the one in effect, so untouched defaults never block a save.
     */
    private function withinServerCap(string $configPath): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($configPath): void {
            if ((int) $value === (int) config($configPath)) {
                return;
            }

            $cap = app(ServerCapabilities::class)->uploadCapBytes();

            if ($cap !== null && (int) $value * 1024 * 1024 > $cap) {
                $fail(__('hata::media.errors.limit_above_server', ['size' => Number::fileSize($cap)]));
            }
        };
    }
}
