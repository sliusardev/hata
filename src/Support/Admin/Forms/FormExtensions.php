<?php

namespace Hata\Support\Admin\Forms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\View\ComponentAttributeBag;

/**
 * Runs the form cards of a form, whatever kind of form it is (a Livewire page or Fortify's plain registration form).
 * Stateless: the form passes the cards it shows (`FormCards::for()`) and the values it holds, keyed by card key.
 *
 * A card's values are untrusted input: only the fields its `rules()` name are validated and passed to `save()`, and
 * the values of a card the form does not show are never read.
 */
final class FormExtensions
{
    /**
     * Every card's initial values, by card key.
     *
     * @param  list<FormCard>  $cards
     * @return array<string, array<string, mixed>>
     */
    public function fill(array $cards, ?Model $record, FormContext $context): array
    {
        $values = [];

        foreach ($cards as $card) {
            $values[$card->key] = $this->extension($card)->fill($record, $context);
        }

        return $values;
    }

    /**
     * Every card's rules, keyed by their path in the form's data: `extensions.<card key>.<field>`.
     *
     * @param  list<FormCard>  $cards
     * @return array<string, mixed>
     */
    public function rules(array $cards, FormContext $context): array
    {
        $rules = [];

        foreach ($cards as $card) {
            foreach ($this->extension($card)->rules($context) as $field => $fieldRules) {
                $rules["extensions.{$card->key}.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    /**
     * The name a validation message gives each field: the field's own name, as for a field of the form itself
     * ("The birthday field is required", not "The extensions.profile.birthday field ...").
     *
     * @param  list<FormCard>  $cards
     * @return array<string, string>
     */
    public function attributes(array $cards, FormContext $context): array
    {
        $attributes = [];

        foreach (array_keys($this->rules($cards, $context)) as $path) {
            $attributes[$path] = str_replace('_', ' ', (string) last(explode('.', $path)));
        }

        return $attributes;
    }

    /**
     * Calls each card's `save()` with the values of the fields its rules name (a field it names that was not posted
     * is null). Call it inside the form's transaction, after the record is saved and once the values are valid.
     *
     * @param  list<FormCard>  $cards
     * @param  array<array-key, mixed>  $values  by card key
     */
    public function save(array $cards, Model $record, array $values, FormContext $context): void
    {
        foreach ($cards as $card) {
            $extension = $this->extension($card);
            $posted = is_array($values[$card->key] ?? null) ? $values[$card->key] : [];
            $own = [];

            foreach (array_keys($extension->rules($context)) as $field) {
                $name = explode('.', (string) $field)[0];
                $own[$name] = $posted[$name] ?? null;
            }

            $extension->save($record, $own, $context);
        }
    }

    /**
     * The attributes binding one of a card's inputs: `wire:model` in a Livewire form, else the posted name and the
     * old input value.
     */
    public function field(string $cardKey, string $field, bool $livewire): ComponentAttributeBag
    {
        if ($livewire) {
            return new ComponentAttributeBag(['wire:model' => "extensions.{$cardKey}.{$field}"]);
        }

        return new ComponentAttributeBag([
            'name' => "extensions[{$cardKey}][{$field}]",
            'value' => old("extensions.{$cardKey}.{$field}"),
        ]);
    }

    private function extension(FormCard $card): FormExtension
    {
        return app($card->extension);
    }
}
