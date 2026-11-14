<?php

namespace App\Support\Content;

use InvalidArgumentException;

/**
 * The content types the site knows (pages, later posts), registered by service providers.
 */
class ContentTypes
{
    /**
     * @var array<string, ContentType>
     */
    private array $types = [];

    public function register(ContentType $type): static
    {
        if (preg_match('/^[a-z0-9_-]+$/', $type->key()) !== 1) {
            throw new InvalidArgumentException("Invalid content type key [{$type->key()}].");
        }

        $this->types[$type->key()] = $type;

        return $this;
    }

    /**
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        return $this->types;
    }

    public function find(string $key): ?ContentType
    {
        return $this->types[$key] ?? null;
    }

    public function get(string $key): ContentType
    {
        return $this->find($key) ?? throw new InvalidArgumentException("Unknown content type [{$key}].");
    }

    /**
     * An alternation of the type keys for a route constraint, e.g. "pages|posts". Matches nothing
     * when no type is registered.
     */
    public function pattern(): string
    {
        if ($this->types === []) {
            return '(?!)';
        }

        return implode('|', array_map(preg_quote(...), array_keys($this->types)));
    }
}
