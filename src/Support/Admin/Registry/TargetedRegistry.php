<?php

namespace Hata\Support\Admin\Registry;

use Hata\Support\Content\ContentTypes;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * A registry whose entries are aimed at a list: `users`, every content list (`content.*`), the lists of records
 * (`content.@records`) or of taxonomies (`content.@taxonomies`), or one content type (`content.posts`).
 *
 * `add()`, `remove()` and `modify()` are recorded per target, in call order, and nothing is decided until a list asks
 * for its own registry: `resolve()` replays the calls of the target's chain (`content.*`, then its group, then the
 * type) into a fresh registry, adds first, then the modifications, then the removals, each from the widest target to
 * the narrowest. So a narrower target overrides a wider one's entry of the same key, and removes or changes it for
 * itself only, whatever order the providers booted in.
 *
 * A target that is not one of the valid ones is a registration mistake: thrown in the local and testing
 * environments, skipped and logged once per process elsewhere.
 *
 * @template TRegistry of Registry
 */
abstract class TargetedRegistry
{
    /**
     * The targets that are not under `content`. Add a target here to open it to every targeted registry.
     */
    public const PLAIN_TARGETS = ['users', 'profile', 'register'];

    /**
     * @var list<array{string, string, mixed}>
     */
    private array $operations = [];

    /**
     * @var array<string, TRegistry>
     */
    private array $resolved = [];

    /**
     * @var array<class-string, array<string, true>>
     */
    private static array $warned = [];

    /**
     * @return TRegistry
     */
    abstract protected function newRegistry(): Registry;

    /**
     * What the registry holds, for messages: "list column", ….
     */
    abstract public function name(): string;

    /**
     * Starts a call aimed at one or several targets.
     *
     * @param  string|list<string>  $targets
     */
    public function on(string|array $targets): TargetScope
    {
        $valid = [];

        foreach ((array) $targets as $target) {
            if ($this->validTarget($target)) {
                $valid[] = $target;
            } else {
                $this->report("The {$this->name()} target [{$target}] is not valid: use ".implode(', ', static::PLAIN_TARGETS).', content.*, content.@records, content.@taxonomies or content.<type key>.');
            }
        }

        return new TargetScope($this, $valid);
    }

    /**
     * @internal Called by TargetScope.
     */
    public function record(string $target, string $operation, mixed $payload): void
    {
        $this->operations[] = [$target, $operation, $payload];
        $this->resolved = [];
    }

    /**
     * The registry a list of this target reads: a fresh one, memoized until the next call.
     *
     * @return TRegistry
     */
    public function resolve(string $target): Registry
    {
        if (isset($this->resolved[$target])) {
            return $this->resolved[$target];
        }

        $registry = $this->newRegistry();
        $chain = $this->chain($target);

        foreach (['add', 'modify', 'remove'] as $operation) {
            foreach ($chain as $link) {
                foreach ($this->operations as [$on, $kind, $payload]) {
                    if ($on !== $link || $kind !== $operation) {
                        continue;
                    }

                    match ($operation) {
                        'add' => $registry->add($payload),
                        'modify' => $registry->modify($payload[0], $payload[1]),
                        'remove' => $registry->remove($payload),
                    };
                }
            }
        }

        return $this->resolved[$target] = $registry;
    }

    /**
     * Every target that received a call, for `hata:admin`.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        return array_values(array_unique(array_map(fn (array $operation): string => $operation[0], $this->operations)));
    }

    /**
     * The targets whose calls apply to a target, from the widest to the narrowest: `content.posts` is
     * `content.*`, `content.@records`, `content.posts`. Reads the content types, so call it when a list opens.
     *
     * @return list<string>
     */
    public function chain(string $target): array
    {
        if (! str_starts_with($target, 'content.') || $target === 'content.*') {
            return [$target];
        }

        if (str_starts_with($target, 'content.@')) {
            return ['content.*', $target];
        }

        $type = app(ContentTypes::class)->find(substr($target, 8));
        $group = $type !== null && $type->isTaxonomy() ? 'content.@taxonomies' : 'content.@records';

        return ['content.*', $group, $target];
    }

    protected function validTarget(string $target): bool
    {
        if (in_array($target, static::PLAIN_TARGETS, true)) {
            return true;
        }

        return in_array($target, ['content.*', 'content.@records', 'content.@taxonomies'], true)
            || preg_match('/\Acontent\.[a-z0-9_-]+\z/', $target) === 1;
    }

    private function report(string $message): void
    {
        if (app()->environment('local', 'testing')) {
            throw new LogicException($message);
        }

        if (isset(self::$warned[static::class][$message])) {
            return;
        }

        self::$warned[static::class][$message] = true;

        Log::warning($message);
    }

    /**
     * Forgets which mistakes were logged. For tests only.
     *
     * @internal
     */
    public static function forgetWarnings(): void
    {
        unset(self::$warned[static::class]);
    }
}
