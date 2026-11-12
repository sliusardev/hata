<?php

namespace App\Support\Media;

use App\Support\Settings\SiteSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Whether locally stored images may be handed out as static `/storage/...` URLs.
 *
 * Two things must hold: the web server can reach the `public` disk (the `storage:link` symlink exists and resolves to
 * its root), and a browser-side probe confirmed that the public URL really serves the probe image (loaded the way an
 * `<img>` loads, so no CORS and no dotfile rule can make it differ from real images). The probe result is stored with
 * the URL it was made for, so changing the URL invalidates it.
 *
 * Cost: the link check runs once per process (memoized) and the stored values come from SiteSettings' in-process
 * copy, so state() and active() do no I/O after their first call in a request.
 *
 * Probing backs off: an attempt (the layout handing out an automatic probe, or a recorded result) is remembered with
 * its URL, and no new automatic probe is due for RETRY_MINUTES after it unless the URL changed; a confirmed state is
 * rechecked after RECHECK_HOURS.
 */
class DirectServing
{
    public const string STATE_DIRECT = 'direct';

    public const string STATE_UNCHECKED = 'unchecked';

    public const string STATE_LINK_MISSING = 'link_missing';

    public const string STATE_UNREACHABLE = 'unreachable';

    /** The probe image on the `public` disk: a PNG whose random size only the server knows. Not a dotfile, which web servers often deny. */
    public const string PROBE_PATH = 'media/probe.png';

    /** The text probe file of the first version, removed when the probe image is written. */
    public const string LEGACY_PROBE_PATH = 'media/.probe';

    public const int PROBE_MIN_SIDE = 2;

    public const int PROBE_MAX_SIDE = 64;

    /** Hours after which a confirmed state is probed again. */
    public const int RECHECK_HOURS = 24;

    /** Minutes after an attempt during which no automatic probe is due, and a same result is not written again. */
    public const int RETRY_MINUTES = 30;

    private ?bool $linked = null;

    /** @var array{width: int, height: int}|null */
    private ?array $probeSize = null;

    public function __construct(private readonly SiteSettings $settings) {}

    /**
     * Replace the container's instance with one that gives fixed answers: `$linked` false is `link_missing`,
     * linked but not `$confirmed` is `unchecked`, both is `direct`. An explicit `$state` (a `STATE_*` constant, for
     * `unreachable`, which the flags cannot express) wins over the flags. For tests.
     */
    public static function fake(bool $linked = true, bool $confirmed = true, ?string $state = null): void
    {
        app()->instance(self::class, new class(app(SiteSettings::class), $linked, $confirmed, $state) extends DirectServing
        {
            public function __construct(SiteSettings $settings, private readonly bool $fakeLinked, private readonly bool $fakeConfirmed, private readonly ?string $fakeState)
            {
                parent::__construct($settings);
            }

            protected function resolveLinked(): bool
            {
                return $this->fakeLinked;
            }

            public function state(): string
            {
                return $this->fakeState ?? match (true) {
                    ! $this->fakeLinked => self::STATE_LINK_MISSING,
                    $this->fakeConfirmed => self::STATE_DIRECT,
                    default => self::STATE_UNCHECKED,
                };
            }
        });
    }

    /**
     * Whether a link from `filesystems.links` exists and resolves to the `public` disk's root. Memoized.
     */
    public function linked(): bool
    {
        return $this->linked ??= $this->resolveLinked();
    }

    /**
     * Forget the memoized link check, after the link was created or removed.
     */
    public function refresh(): void
    {
        $this->linked = null;
    }

    /**
     * One of the `STATE_*` constants.
     */
    public function state(): string
    {
        if (! $this->linked()) {
            return self::STATE_LINK_MISSING;
        }

        $url = MediaDisks::publicUrl(MediaDisks::PUBLIC);
        $stored = $this->settings->stored();

        if ($url === null || ($stored['media_direct_url'] ?? null) !== $url) {
            return self::STATE_UNCHECKED;
        }

        return match ($stored['media_direct_state'] ?? null) {
            'ok' => self::STATE_DIRECT,
            'failed' => self::STATE_UNREACHABLE,
            default => self::STATE_UNCHECKED,
        };
    }

    /**
     * Whether files on the disk are served by static URLs right now.
     */
    public function active(string $disk): bool
    {
        return MediaDisks::publishes($disk) && $this->state() === self::STATE_DIRECT;
    }

    public function probeUrl(): string
    {
        return MediaDisks::publicUrl(MediaDisks::PUBLIC).'/'.self::PROBE_PATH;
    }

    /**
     * The pixel size of the probe image, which the browser compares with what it loaded. The image is read once per
     * process; when it is missing or not a probe image, a new one of a random size is written (and the legacy text
     * probe removed).
     *
     * @return array{width: int, height: int}
     *
     * @throws RuntimeException when the probe image can't be written
     */
    public function probeSize(): array
    {
        if ($this->probeSize !== null) {
            return $this->probeSize;
        }

        $disk = Storage::disk(MediaDisks::PUBLIC);
        $size = self::sizeOfProbe($disk->get(self::PROBE_PATH));

        if ($size === null) {
            $size = [
                'width' => random_int(self::PROBE_MIN_SIDE, self::PROBE_MAX_SIDE),
                'height' => random_int(self::PROBE_MIN_SIDE, self::PROBE_MAX_SIDE),
            ];

            if ($disk->put(self::PROBE_PATH, self::probeImage($size['width'], $size['height'])) === false) {
                throw new RuntimeException('Could not write the probe image ['.self::PROBE_PATH.'] on the public disk.');
            }

            $disk->delete(self::LEGACY_PROBE_PATH);
        }

        return $this->probeSize = $size;
    }

    /**
     * A grey 8-bit greyscale PNG of the given size, built by hand (an uncompressed deflate block), so it needs no
     * image extension. Valid for sides up to PROBE_MAX_SIDE (one deflate block holds 65535 bytes).
     */
    public static function probeImage(int $width, int $height): string
    {
        $pixels = str_repeat("\0".str_repeat("\x80", $width), $height);
        $length = strlen($pixels);
        $deflate = "\x78\x01\x01".pack('v', $length).pack('v', ~$length & 0xFFFF).$pixels.hash('adler32', $pixels, true);

        return "\x89PNG\r\n\x1a\n"
            .self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0))
            .self::pngChunk('IDAT', $deflate)
            .self::pngChunk('IEND', '');
    }

    /**
     * Store the result of a probe made against `$url`. Nothing is written when the same result for the same URL was
     * recorded less than RETRY_MINUTES ago, unless `$force` (the "Check again" button). The write touches only the
     * stored-only probe values: the config, logos and mailers are left alone.
     */
    public function record(bool $ok, string $url, bool $force = false): void
    {
        $stored = $this->settings->stored();
        $state = $ok ? 'ok' : 'failed';

        if (! $force
            && ($stored['media_direct_state'] ?? null) === $state
            && ($stored['media_direct_url'] ?? null) === $url
            && self::isRecent($stored['media_direct_checked_at'] ?? null, self::RETRY_MINUTES)) {
            return;
        }

        $now = Carbon::now('UTC')->toIso8601String();

        $this->settings->putStoredOnly([
            'media_direct_state' => $state,
            'media_direct_url' => $url,
            'media_direct_checked_at' => $now,
            'media_direct_attempted_at' => $now,
            'media_direct_attempted_url' => $url,
        ]);

        $this->linked = null;
    }

    /**
     * Remember that an automatic probe was handed out for the current URL, so the next one waits RETRY_MINUTES even
     * if this one's report never arrives.
     */
    public function markAttempt(): void
    {
        $this->settings->putStoredOnly([
            'media_direct_attempted_at' => Carbon::now('UTC')->toIso8601String(),
            'media_direct_attempted_url' => MediaDisks::publicUrl(MediaDisks::PUBLIC),
        ]);
    }

    /**
     * Whether the browser should probe on its own: never while the link is missing or within RETRY_MINUTES of an
     * attempt for the same URL; otherwise while nothing is confirmed (unchecked, unreachable), or once the
     * confirmation is RECHECK_HOURS old.
     */
    public function dueForCheck(): bool
    {
        $state = $this->state();

        if ($state === self::STATE_LINK_MISSING) {
            return false;
        }

        $stored = $this->settings->stored();

        if (($stored['media_direct_attempted_url'] ?? null) === MediaDisks::publicUrl(MediaDisks::PUBLIC)
            && self::isRecent($stored['media_direct_attempted_at'] ?? null, self::RETRY_MINUTES)) {
            return false;
        }

        if ($state !== self::STATE_DIRECT) {
            return true;
        }

        return ! self::isRecent($stored['media_direct_checked_at'] ?? null, self::RECHECK_HOURS * 60);
    }

    /**
     * The links configured in `filesystems.links` (link path => target).
     *
     * @return array<string, string>
     */
    protected function links(): array
    {
        /** @var array<string, string> */
        return config('filesystems.links', []);
    }

    protected function resolveLinked(): bool
    {
        $root = config('filesystems.disks.'.MediaDisks::PUBLIC.'.root');
        $rootPath = is_string($root) ? realpath($root) : false;

        if ($rootPath === false) {
            return false;
        }

        foreach ($this->links() as $link => $target) {
            if (is_link($link) && realpath($target) === $rootPath && realpath($link) === $rootPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a stored ISO 8601 time lies less than `$minutes` in the past.
     */
    private static function isRecent(mixed $time, int $minutes): bool
    {
        return is_string($time) && Carbon::parse($time)->gt(Carbon::now('UTC')->subMinutes($minutes));
    }

    /**
     * The size of a probe image written by probeImage(), null for anything else (missing, not a PNG, out of range).
     *
     * @return array{width: int, height: int}|null
     */
    private static function sizeOfProbe(?string $bytes): ?array
    {
        if ($bytes === null || strlen($bytes) < 24 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }

        /** @var array{1: int, 2: int} $size */
        $size = unpack('N2', substr($bytes, 16, 8));

        foreach ($size as $side) {
            if ($side < self::PROBE_MIN_SIDE || $side > self::PROBE_MAX_SIDE) {
                return null;
            }
        }

        return ['width' => $size[1], 'height' => $size[2]];
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
