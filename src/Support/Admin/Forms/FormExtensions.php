<?php

namespace Hata\Support\Admin\Forms;

use Hata\Exceptions\FormCardFailed;
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
            $extension = $this->extension($card);

            // Every field the rules name is there, so the card's values are an object in the browser from the start.
            $values[$card->key] = [...array_fill_keys($this->fields($extension, $context), null), ...$extension->fill($record, $context)];
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
     * Whatever a card throws comes out as a `FormCardFailed` naming the card.
     *
     * @param  list<FormCard>  $cards
     * @param  array<array-key, mixed>  $values  by card key
     *
     * @throws FormCardFailed
     */
    public function save(array $cards, Model $record, array $values, FormContext $context): void
    {
        foreach ($cards as $card) {
            $extension = $this->extension($card);
            $posted = is_array($values[$card->key] ?? null) ? $values[$card->key] : [];
            $own = [];

            foreach ($this->fields($extension, $context) as $field) {
                $own[$field] = $posted[$field] ?? null;
            }

            FormCardFailed::guard(fn () => $extension->save($record, $own, $context), $card->key);
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

    /**
     * The top-level fields a card's rules name (`tags.*` names `tags`).
     *
     * @return list<string>
     */
    private function fields(FormExtension $extension, FormContext $context): array
    {
        return array_values(array_unique(array_map(
            fn (int|string $field): string => explode('.', (string) $field)[0],
            array_keys($extension->rules($context)),
        )));
    }

    private function extension(FormCard $card): FormExtension
    {
        return app($card->extension);
    }
}
