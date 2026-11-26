<?php

namespace Hata\Support\Admin\Forms;

use Illuminate\Database\Eloquent\Model;

/**
 * What a form card does, written by a site or module and registered with `Admin::formCards(...)->add(new FormCard(...))`.
 *
 * A card is part of the form, not a component of its own: its values live in the form page's `extensions` array
 * under the card's key, are validated together with the form (any error stops the whole save) and are saved inside
 * the form's transaction, after the record is saved and before any redirect. An exception in `save()` rolls the
 * record back too.
 *
 * The class is resolved through the container on every call, so it may take dependencies in its constructor; it
 * must keep no state between calls.
 */
abstract class FormExtension
{
    /**
     * The card's initial values, by field name (on create `$record` is null). Runs once when the form opens: read
     * what the card needs in one query (the record is passed, so a relation is one query).
     *
     * @return array<string, mixed>
     */
    abstract public function fill(?Model $record, FormContext $context): array;

    /**
     * Validation rules by field name, relative to the card (`['birthday' => ['nullable', 'date']]`). Only the fields
     * named here reach `save()`, whatever the browser posts; the filter is by top-level field (`tags.*` lets the whole
     * `tags` value through, checked by its rules). Every named field is in the card's values from the start (null
     * when `fill()` leaves it out). Validate here, not in `save()`: a check in `rules()` is shown with the form's own
     * errors before anything is written.
     *
     * @return array<string, mixed>
     */
    abstract public function rules(FormContext $context): array;

    /**
     * The Blade view drawn inside Hata's card frame. It receives `$key` (the card's key), `$field` (a
     * `Closure(string): ComponentAttributeBag` binding an input: `<flux:input :attributes="$field('birthday')" />`),
     * `$errorName` (a `Closure(string): string`, the error bag key: `<flux:error :name="$errorName('birthday')" />`)
     * and `$values` (the card's current values). Interactivity is Alpine only, no Livewire calls of its own.
     */
    abstract public function view(): string;

    /**
     * Stores the validated values of the fields `rules()` names, inside the form's transaction; on create `$record` is
     * the new record. Anything it throws rolls the record back: a `ValidationException` (keys relative to the card)
     * is shown under the card's fields, anything else is thrown as it is.
     *
     * @param  array<string, mixed>  $values
     */
    abstract public function save(Model $record, array $values, FormContext $context): void;
}
