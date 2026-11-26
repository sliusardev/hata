<?php

namespace Hata\Support\Content;

use Hata\Rules\EntrySlug;
use Hata\Support\Content\Fields\Field;
use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * One kind of content: its address segment, permission group, extra fields and tree shape.
 */
abstract class ContentType
{
    /**
     * The stored `entries.type`, the admin address segment and the permission group.
     */
    abstract public function key(): string;

    abstract public function icon(): string;

    /**
     * Whether records of this type form a tree (a parent and a path of slugs).
     */
    abstract public function hierarchical(): bool;

    /**
     * The first address segment of this type in a language (e.g. `blog`), or null when its records sit
     * at the root of the address.
     */
    abstract public function base(string $locale): ?string;

    /**
     * Refuses a base that is not one segment in the slug format (EntrySlug::FORMAT), such as `blog/news`, `/blog` or
     * `Blog`: the content route would never match the addresses Entry::url() builds with it. Null (the root) passes.
     *
     * @throws InvalidArgumentException
     */
    public function assertValidBase(string $locale): void
    {
        $base = $this->base($locale);

        if ($base !== null && preg_match(EntrySlug::FORMAT, $base) !== 1) {
            throw new InvalidArgumentException("The base [{$base}] of content type [{$this->key()}] in [{$locale}] is not a slug.");
        }
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Whether the site cannot do without the type: it then has no switch in Site settings → Content types and
     * is always on.
     */
    public function required(): bool
    {
        return false;
    }

    /**
     * The keys of the types this type is a taxonomy of (`['posts']` for categories and tags). A type that names any
     * is a taxonomy: its records are terms, a record of a named type is filed under terms of its own language, and
     * the taxonomy is on while any of those types is.
     *
     * @return list<string>
     */
    public function taxonomyOf(): array
    {
        return [];
    }

    /**
     * Whether the admin list of the types this taxonomy belongs to has a filter by its terms. Return false to
     * leave the filter out (a taxonomy with thousands of terms, or one nobody filters by).
     */
    public function hasListFilter(): bool
    {
        return true;
    }

    public function isTaxonomy(): bool
    {
        return $this->taxonomyOf() !== [];
    }

    /**
     * The parts of the common form this type does not use: `body`, `image`, `publication` (status and date),
     * `author`, `excerpt` and `seo`. A part that is off leaves the form, and a save never writes it, so a stored
     * value stays as it is. A site's type returns the ones it wants gone (`['author', 'excerpt']`).
     *
     * @return list<string>
     */
    protected function without(): array
    {
        return [];
    }

    /**
     * Whether a record of the type has a part of the common form (see `without()`). A term has only `excerpt` and
     * `seo`: it is a name, an address and a description, always published.
     */
    public function supports(string $feature): bool
    {
        if ($this->isTaxonomy() && ! in_array($feature, ['excerpt', 'seo'], true)) {
            return false;
        }

        return ! in_array($feature, $this->without(), true);
    }

    /**
     * Where the type's translations live: empty for a site's or a module's type (`content.types.<key>.*` in the
     * site's own lang files), `hata::` for the types Hata ships.
     */
    public function translationPrefix(): string
    {
        return '';
    }

    public function label(): string
    {
        return __("{$this->translationPrefix()}content.types.{$this->key()}.label");
    }

    public function pluralLabel(): string
    {
        return __("{$this->translationPrefix()}content.types.{$this->key()}.plural");
    }

    /**
     * One line under the admin list's heading, from `content.types.<key>.description`.
     */
    public function description(): string
    {
        return __("{$this->translationPrefix()}content.types.{$this->key()}.description");
    }

    /**
     * The admin list's "Create" button, from `content.types.<key>.create` ("Create page").
     */
    public function createLabel(): string
    {
        return __("{$this->translationPrefix()}content.types.{$this->key()}.create");
    }

    /**
     * The heading of the form for a new record ("New page"), from `content.types.<key>.new`; the create label
     * when the type has no such line.
     */
    public function newLabel(): string
    {
        $key = "{$this->translationPrefix()}content.types.{$this->key()}.new";

        return Lang::has($key) ? __($key) : $this->createLabel();
    }

    public function perPage(): int
    {
        return 20;
    }

    public function permission(string $action): string
    {
        return "{$this->key()}.{$action}";
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields() as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Fields whose value is the same in every language of a record group.
     *
     * @return list<Field>
     */
    public function sharedFields(): array
    {
        return array_values(array_filter($this->fields(), fn (Field $field): bool => $field->isShared));
    }

    /**
     * @return list<Field>
     */
    public function localFields(): array
    {
        return array_values(array_filter($this->fields(), fn (Field $field): bool => ! $field->isShared));
    }
}
