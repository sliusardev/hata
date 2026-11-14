<?php

namespace App\Support\Content;

use App\Support\Content\Fields\Field;
use Illuminate\Support\Facades\Lang;

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
     * @return list<Field>
     */
    public function fields(): array
    {
        return [];
    }

    public function label(): string
    {
        return __("content.types.{$this->key()}.label");
    }

    public function pluralLabel(): string
    {
        return __("content.types.{$this->key()}.plural");
    }

    /**
     * One line under the admin list's heading, from `content.types.<key>.description`.
     */
    public function description(): string
    {
        return __("content.types.{$this->key()}.description");
    }

    /**
     * The admin list's "Create" button, from `content.types.<key>.create` ("Create page").
     */
    public function createLabel(): string
    {
        return __("content.types.{$this->key()}.create");
    }

    /**
     * The heading of the form for a new record ("New page"), from `content.types.<key>.new`; the create label
     * when the type has no such line.
     */
    public function newLabel(): string
    {
        $key = "content.types.{$this->key()}.new";

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
