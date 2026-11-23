<?php

namespace Hata\Support\Content;

use Hata\Support\Localization\LanguageCatalog;
use Hata\Support\Permissions\PermissionRegistry;
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

        $this->types[$type->key()] = $type;
        $this->permissions->register($type->key(), self::ACTIONS, "{$type->translationPrefix()}permissions");

        return $this;
    }

    /**
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The keys switched off in Site settings → Content types (`content.disabled_types`, a stored site setting read
     * at call time, so a change applies at once).
     *
     * @return list<string>
     */
    public function disabledKeys(): array
    {
        return array_values(array_filter((array) config('content.disabled_types', []), is_string(...)));
    }

    /**
     * Whether the type is registered and switched on. A type that is off keeps its records, permissions and
     * reserved base, but has no admin pages and no public addresses.
     */
    public function isActive(string $key): bool
    {
        $type = $this->find($key);

        if ($type === null) {
            return false;
        }

        // A taxonomy has no switch of its own: it is on while a type it classifies is.
        if ($type->isTaxonomy()) {
            foreach ($type->taxonomyOf() as $classified) {
                if ($this->isActive($classified)) {
                    return true;
                }
            }

            return false;
        }

        return $type->required() || ! in_array($key, $this->disabledKeys(), true);
    }

    /**
     * The types that are switched on, in registration order.
     *
     * @return array<string, ContentType>
     */
    public function active(): array
    {
        return array_filter($this->types, fn (ContentType $type): bool => $this->isActive($type->key()));
    }

    /**
     * Checks that every registered type's base is one slug in every language of the catalogue. Not part of
     * `register()`: it is about 130 calls per type, and the registry is built on every request, while a wrong base is
     * a developer's mistake that `make:content-type`, `content:check` and the test suite catch.
     *
     * @throws InvalidArgumentException
     */
    public function assertValidBases(): void
    {
        $locales = app(LanguageCatalog::class)->codes();

        foreach ($this->types as $type) {
            foreach ($locales as $locale) {
                $type->assertValidBase($locale);
            }
        }
    }

    /**
     * The taxonomies whose terms a record of the type can be filed under, in registration order.
     *
     * @return array<string, ContentType>
     */
    public function taxonomiesOf(string $key): array
    {
        return array_filter($this->types, fn (ContentType $type): bool => in_array($key, $type->taxonomyOf(), true));
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
