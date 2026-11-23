<?php

namespace Tests\Fixtures;

use App\Models\User;
use Hata\Contracts\UsesMedia;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only usable over the `media_using_models` table that the test creates.
 */
class MediaUsingModel extends Model implements UsesMedia
{
    /** @var list<array{int, string, int}> */
    public static array $forgotten = [];

    protected $guarded = [];

    public function mediaUsageLabel(string $field): string
    {
        return 'Fixture '.$this->getKey().' / '.$field;
    }

    public function mediaUsageUrl(string $field, ?User $viewer): ?string
    {
        return $viewer === null ? null : 'https://example.test/fixtures/'.$this->getKey();
    }

    public function forgetMedia(string $field, int $mediaId): void
    {
        self::$forgotten[] = [(int) $this->getKey(), $field, $mediaId];
    }
}
