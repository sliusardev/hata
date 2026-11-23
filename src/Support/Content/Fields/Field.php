<?php

namespace Hata\Support\Content\Fields;

use Illuminate\Support\Facades\Lang;

/**
 * An extra field of a content type. Converts between the form value and the stored value.
 */
abstract class Field
{
    public bool $isShared = false;

    public bool $isRequired = false;

    public ?string $labelKey = null;

    final public function __construct(public readonly string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    /**
     * A translation key for the field's label.
     */
    public function label(string $key): static
    {
        $this->labelKey = $key;

        return $this;
    }

    public function labelText(): string
    {
        if ($this->labelKey !== null) {
            return __($this->labelKey);
        }

        $siteKey = "content.fields.{$this->name}";

        return Lang::has($siteKey) ? __($siteKey) : __("hata::content.fields.{$this->name}");
    }

    /**
     * Same value in every language of a record group.
     */
    public function shared(): static
    {
        $this->isShared = true;

        return $this;
    }

    public function required(): static
    {
        $this->isRequired = true;

        return $this;
    }

    /**
     * Validation rules for the field's value.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        return [$this->isRequired ? 'required' : 'nullable', ...$this->typeRules()];
    }

    /**
     * @return list<mixed>
     */
    protected function typeRules(): array
    {
        return [];
    }

    public function toStorage(mixed $formValue): mixed
    {
        return $formValue;
    }

    public function toForm(mixed $stored): mixed
    {
        return $stored;
    }

    /**
     * The Blade partial that renders the input, under `partials.admin.content.fields`.
     */
    abstract public function view(): string;

    /**
     * Media ids a stored value refers to.
     *
     * @return list<int>
     */
    public function mediaIds(mixed $stored): array
    {
        return [];
    }

    /**
     * Whether the field refers to media files, so its usages are recorded.
     */
    public function holdsMedia(): bool
    {
        return false;
    }

    /**
     * The stored value without a media file that is being deleted.
     */
    public function withoutMedia(mixed $stored, int $mediaId): mixed
    {
        return $stored;
    }
}
