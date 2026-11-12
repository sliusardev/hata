<?php

namespace App\Support\Media;

use App\Support\Settings\SiteSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Whether locally stored images may be handed out as static `/storage/...` URLs.
 *
 * Two things must hold: the web server can reach the `public` disk (the `storage:link` symlink exists and resolves to
 * its root), and a browser-side probe confirmed that the public URL really returns the probe file. The probe result
 * is stored with the URL it was made for, so changing the URL invalidates it.
 */
class DirectServing
{
    public const string STATE_DIRECT = 'direct';

    public const string STATE_UNCHECKED = 'unchecked';

    public const string STATE_LINK_MISSING = 'link_missing';

    public const string STATE_UNREACHABLE = 'unreachable';

    public const string PROBE_PATH = 'media/.probe';

    private const int RECHECK_HOURS = 24;

    private ?bool $linked = null;

    public function __construct(private readonly SiteSettings $settings) {}

    /**
     * Replace the container's instance with one that gives fixed answers: `$linked` false is `link_missing`,
     * linked but not `$confirmed` is `unchecked`, both is `direct`. For tests.
     */
    public static function fake(bool $linked = true, bool $confirmed = true): void
    {
        app()->instance(self::class, new class(app(SiteSettings::class), $linked, $confirmed) extends DirectServing
        {
            public function __construct(SiteSettings $settings, private readonly bool $fakeLinked, private readonly bool $fakeConfirmed)
            {
                parent::__construct($settings);
            }

            protected function resolveLinked(): bool
            {
                return $this->fakeLinked;
            }

            public function state(): string
            {
                return match (true) {
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
     * The content of the probe file, written with a random token when it does not exist yet.
     */
    public function probeToken(): string
    {
        $disk = Storage::disk(MediaDisks::PUBLIC);

        if (! $disk->exists(self::PROBE_PATH)) {
            $disk->put(self::PROBE_PATH, Str::random(40));
        }

        return (string) $disk->get(self::PROBE_PATH);
    }

    /**
     * Store the result of a probe made against `$url`.
     */
    public function record(bool $ok, string $url): void
    {
        $this->settings->put([
            'media_direct_state' => $ok ? 'ok' : 'failed',
            'media_direct_url' => $url,
            'media_direct_checked_at' => Carbon::now('UTC')->toIso8601String(),
        ]);

        $this->linked = null;
    }

    /**
     * Whether the browser should probe again: nothing confirmed or the last probe failed, or the last one is old.
     */
    public function dueForCheck(): bool
    {
        $state = $this->state();

        if ($state === self::STATE_LINK_MISSING) {
            return false;
        }

        if ($state !== self::STATE_DIRECT) {
            return true;
        }

        $checkedAt = $this->settings->stored()['media_direct_checked_at'] ?? null;

        return ! is_string($checkedAt) || Carbon::parse($checkedAt)->lt(Carbon::now('UTC')->subHours(self::RECHECK_HOURS));
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
}
