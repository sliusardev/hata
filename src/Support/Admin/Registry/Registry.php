<?php

namespace Hata\Support\Admin\Registry;

use Closure;
use Hata\Contracts\AdminUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The shared base of the admin registries (the menu, the settings sections, …).
 *
 * Hata, a site and a module register in their providers' `boot()`, in whatever order Laravel boots them, so nothing
 * is decided when a call is made: `add()` keeps the entries, `remove()` and `modify()` are recorded and applied when
 * the registry is read. A site may therefore remove or change one of Hata's entries before Hata has added it. A
 * `modify()` or `remove()` that matches no entry is skipped and listed by `unmatched()`.
 *
 * A registration mistake (a bad key, or whatever `validate()` finds) throws a LogicException naming the entry in the
 * local and testing environments, so a module's own tests catch it; elsewhere the entry is left out and a warning is
 * logged once per process. Subclasses are bound as singletons.
 *
 * @template TEntry of Entry
 */
abstract class Registry
{
    /**
     * @var array<string, TEntry>
     */
    private array $entries = [];

    /**
     * @var array<string, true>
     */
    private array $removed = [];

    /**
     * @var list<array{string, Closure(TEntry): TEntry}>
     */
    private array $modifications = [];

    /**
     * @var list<string>
     */
    private array $unmatched = [];

    /**
     * The registration mistakes this process has logged already, per registry class.
     *
     * @var array<class-string, array<string, true>>
     */
    private static array $warned = [];

    /**
     * What the registry holds, in messages: "admin menu item", "settings section", ….
     */
    abstract public function name(): string;

    /**
     * What the entry does, in one line, for `hata:admin`: "route [admin.media.index] in group [content]", ….
     *
     * @param  TEntry  $entry
     */
    abstract public function describe(Entry $entry): string;

    /**
     * Adds an entry; one with the same key replaces the earlier one and keeps its place among equal orders. To replace
     * an entry, add one with the same key: a `remove()` of the key hides it whenever the add happens.
     *
     * @param  TEntry  $entry
     */
    public function add(Entry $entry): static
    {
        $this->entries[$entry->key] = $entry;

        return $this;
    }

    /**
     * remove() hides the key for good; to replace an entry, add() one with the same key. The key is hidden when the
     * registry is read, whether its entry is added before or after this call, including entries worked out at read
     * time.
     */
    public function remove(string $key): static
    {
        $this->removed[$key] = true;

        return $this;
    }

    /**
     * Changes an entry when the registry is read, whether it is added before or after this call. Changes apply in the
     * order they were registered.
     *
     * @param  Closure(TEntry): TEntry  $change
     */
    public function modify(string $key, Closure $change): static
    {
        $this->modifications[] = [$key, $change];

        return $this;
    }

    /**
     * Whether the key is registered and not removed, whoever may see it.
     */
    public function has(string $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * The entry of a key, with its modifications, or null when it is not registered or removed.
     *
     * @return TEntry|null
     */
    public function find(string $key): ?Entry
    {
        return $this->resolve()['entries'][$key] ?? null;
    }

    /**
     * Every valid entry by order (ties keep the order they were added in), with removals and modifications applied.
     * An invalid entry is reported through `problem()` and left out.
     *
     * @return list<TEntry>
     */
    public function all(): array
    {
        ['entries' => $entries, 'problems' => $problems, 'unmatched' => $this->unmatched] = $this->inspect();

        foreach ($problems as $key => $problem) {
            $this->problem($key, $problem);
        }

        return $entries;
    }

    /**
     * Everything the registry holds, for `hata:admin`: the valid entries in order, the entries it would leave out with
     * their problem, and the unmatched calls. Never throws and never logs.
     *
     * @return array{entries: list<TEntry>, problems: array<string, string>, unmatched: list<string>}
     */
    public function inspect(): array
    {
        ['entries' => $all, 'unmatched' => $unmatched] = $this->resolve();

        $position = array_flip(array_keys($all));
        $problems = [];

        foreach ($all as $key => $entry) {
            $problem = $this->problemOf($entry);

            if ($problem !== null) {
                $problems[$key] = $problem;
                unset($all[$key]);
            }
        }

        uasort($all, fn (Entry $a, Entry $b): int => [$a->order, $position[$a->key]] <=> [$b->order, $position[$b->key]]);

        return ['entries' => array_values($all), 'problems' => $problems, 'unmatched' => $unmatched];
    }

    /**
     * The entries this user may see, in the order of `all()`.
     *
     * @return list<TEntry>
     */
    public function entriesFor(AdminUser $user): array
    {
        return array_values(array_filter($this->all(), fn (Entry $entry): bool => $this->allows($user, $entry)));
    }

    /**
     * @param  TEntry  $entry
     */
    public function allows(AdminUser $user, Entry $entry): bool
    {
        if ($entry->permission === null) {
            return true;
        }

        if ($entry->permission instanceof Closure) {
            return (bool) ($entry->permission)($user);
        }

        return Gate::forUser($user)->allows($entry->permission);
    }

    /**
     * The `modify()` and `remove()` calls that matched no entry when `all()` was last called, as "modify [key]" and
     * "remove [key]".
     *
     * @return list<string>
     */
    public function unmatched(): array
    {
        return $this->unmatched;
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

    /**
     * Entries worked out each time the registry is read, for entries that follow data which can change after boot.
     * They come after the added ones; one with an added key replaces it in its place.
     *
     * @return iterable<TEntry>
     */
    protected function extraEntries(): iterable
    {
        return [];
    }

    /**
     * The pattern every key must match.
     */
    protected function keyPattern(): string
    {
        return '/^[a-z0-9]+([.-][a-z0-9]+)*$/';
    }

    /**
     * The problem reported for a key that does not match `keyPattern()`.
     */
    protected function keyProblem(): string
    {
        return 'its key is not lowercase letters and digits joined by hyphens or dots';
    }

    /**
     * A registry's own check of an entry: the problem, or null when it is fine. Called by `all()` after the key check.
     *
     * @param  TEntry  $entry
     */
    protected function validate(Entry $entry): ?string
    {
        return null;
    }

    /**
     * A registration mistake: thrown where a developer sees it (local and testing), skipped elsewhere and logged once
     * per process, so a production site still renders its admin and a long-running worker does not repeat the warning.
     */
    protected function problem(string $key, string $problem): void
    {
        $message = "The {$this->name()} [{$key}] is left out: {$problem}.";

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
     * The added and extra entries in insertion order, removals dropped and modifications applied, and the calls that
     * matched nothing.
     *
     * @return array{entries: array<string, TEntry>, unmatched: list<string>}
     */
    protected function resolve(): array
    {
        $entries = $this->entries;

        foreach ($this->extraEntries() as $entry) {
            $entries[$entry->key] = $entry;
        }

        $unmatched = [];

        foreach (array_keys($this->removed) as $key) {
            if (isset($entries[$key])) {
                unset($entries[$key]);
            } else {
                $unmatched[] = "remove [{$key}]";
            }
        }

        $modifyUnmatched = [];

        foreach ($this->modifications as [$key, $change]) {
            if (isset($entries[$key])) {
                $entries[$key] = $change($entries[$key]);
            } else {
                $modifyUnmatched[] = "modify [{$key}]";
            }
        }

        return ['entries' => $entries, 'unmatched' => [...$modifyUnmatched, ...$unmatched]];
    }

    /**
     * The problem with an entry: its key first, then the registry's own check; null when it is fine.
     *
     * @param  TEntry  $entry
     */
    private function problemOf(Entry $entry): ?string
    {
        return preg_match($this->keyPattern(), $entry->key) === 1 ? $this->validate($entry) : $this->keyProblem();
    }
}
