<?php

namespace Hata\Support\Admin\Registry;

use Closure;

/**
 * The calls of `TargetedRegistry::on()`: each is recorded for every target of the scope, in call order.
 */
final class TargetScope
{
    /**
     * @param  TargetedRegistry<Registry<Entry>>  $registry
     * @param  list<string>  $targets
     */
    public function __construct(private readonly TargetedRegistry $registry, private readonly array $targets) {}

    public function add(Entry $entry): static
    {
        return $this->record('add', $entry);
    }

    public function remove(string $key): static
    {
        return $this->record('remove', $key);
    }

    /**
     * `Closure(never)`: the scope serves every targeted registry, so it cannot name the entry class the closure
     * receives (a `TableColumn`, a `FormCard`, …); `never` accepts a closure typed for any of them.
     *
     * @param  Closure(never): Entry  $change
     */
    public function modify(string $key, Closure $change): static
    {
        return $this->record('modify', [$key, $change]);
    }

    private function record(string $operation, mixed $payload): static
    {
        foreach ($this->targets as $target) {
            $this->registry->record($target, $operation, $payload);
        }

        return $this;
    }
}
