<?php

namespace App\Support\Content;

use App\Support\Localization\LanguageCatalog;
use App\Support\Permissions\PermissionRegistry;
use InvalidArgumentException;

/**
 * The content types the site knows (pages, later posts), registered by service providers.
 */
class ContentTypes
{
    /**
     * The permission actions every type gets under its key.
     */
    public const array ACTIONS = ['view', 'create', 'edit', 'delete'];

    /**
     * @var array<string, ContentType>
     */
    private array $types = [];

    private readonly PermissionRegistry $permissions;

    public function __construct(?PermissionRegistry $permissions = null)
    {
        $this->permissions = $permissions ?? app(PermissionRegistry::class);
    }

    /**
     * Adds the type and registers its permissions, so a type registered at any time (by a module, or a test)
     * is seen by `permissions:sync` and the role matrix. Refuses a key already registered, and a base that is not
     * one slug in some language of the catalogue (ContentType::assertValidBase()): Entry::url() could not build
     * the address. Checking every catalogue language costs one base() call each (about 130), once per request.
     *
     * @throws InvalidArgumentException
     */
    public function register(ContentType $type): static
    {
        if (preg_match('/\A[a-z0-9_-]+\z/', $type->key()) !== 1) {
            throw new InvalidArgumentException("Invalid content type key [{$type->key()}].");
        }

        if (isset($this->types[$type->key()])) {
            throw new InvalidArgumentException("The content type [{$type->key()}] is already registered.");
        }

        foreach (app(LanguageCatalog::class)->codes() as $locale) {
            $type->assertValidBase($locale);
        }

        $this->types[$type->key()] = $type;
        $this->permissions->register($type->key(), self::ACTIONS);

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
