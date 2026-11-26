<?php

namespace Hata\Livewire\Admin\Concerns;

use Closure;
use Flux\Flux;
use Hata\Exceptions\FormCardFailed;
use Hata\Support\Admin\Forms\FormCard;
use Hata\Support\Admin\Forms\FormCards;
use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtensions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * The registered form cards of a Livewire form page: their values in `extensions` (keyed by card key, outside the
 * page's own form object), validated without writing and saved by the page inside its save action's transaction.
 *
 * The page fills them once on mount, asks `extensionErrors()` before saving (any error stops the whole save, shown
 * together with the form's own) and passes `saveExtensions()` to its action, which calls it with the saved record.
 *
 * @property-read list<FormCard> $formCards
 */
trait HasFormExtensions
{
    /**
     * Each visible card's values, by card key.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $extensions = [];

    /**
     * What the cards are told about this form.
     */
    abstract protected function formContext(): FormContext;

    /**
     * The cards the actor sees on this form, in order.
     *
     * @return list<FormCard>
     */
    #[Computed]
    public function formCards(): array
    {
        return app(FormCards::class)->for($this->formContext());
    }

    protected function mountExtensions(?Model $record): void
    {
        $this->extensions = app(FormExtensions::class)->fill($this->formCards, $record, $this->formContext());
    }

    /**
     * The cards' validation errors by their path (`extensions.<card>.<field>`), or none. Writes nothing and throws
     * nothing.
     *
     * @return array<string, list<string>>
     */
    protected function extensionErrors(): array
    {
        if ($this->formCards === []) {
            return [];
        }

        $extensions = app(FormExtensions::class);
        $context = $this->formContext();

        /** @var array<string, list<string>> */
        return Validator::make(
            ['extensions' => $this->extensions],
            $extensions->rules($this->formCards, $context),
            [],
            $extensions->attributes($this->formCards, $context),
        )->errors()->toArray();
    }

    /**
     * Saves every visible card into the saved record. Called by the save action inside its transaction.
     */
    protected function saveExtensions(Model $record): void
    {
        if ($this->formCards === []) {
            return;
        }

        app(FormExtensions::class)->save($this->formCards, $record, $this->extensions, $this->formContext());
    }

    /**
     * Validates the page's own form with `$validateForm` and the cards together: both sets of errors are shown at
     * once, and the form's validated data comes back only when neither failed (null: nothing may be saved).
     *
     * @template T
     *
     * @param  Closure(): T  $validateForm  Throws a `ValidationException` with the errors keyed as the page shows them (`form.name`), as a Livewire form's `validate()` does.
     * @return T|null
     */
    protected function validateWithCards(Closure $validateForm): mixed
    {
        try {
            $data = $validateForm();
        } catch (ValidationException $exception) {
            // Caught, so Livewire does not put them on the page: they are shown here, with the cards' own.
            $data = null;

            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }
        }

        $errors = $this->extensionErrors();

        foreach ($errors as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError($key, $message);
            }
        }

        return $errors === [] ? $data : null;
    }

    /**
     * A card's `save()` failed and the save was rolled back: its validation messages go under the card's fields (a
     * key the card's view does not show still appears in the danger toast); anything else is thrown as it was.
     */
    protected function showCardFailure(FormCardFailed $failure): void
    {
        $original = $failure->original();

        if (! $original instanceof ValidationException) {
            throw $original;
        }

        $messages = [];

        foreach ($original->errors() as $key => $keyMessages) {
            $path = str_starts_with($key, 'extensions.') || $failure->card === null ? $key : "extensions.{$failure->card}.{$key}";

            foreach ($keyMessages as $message) {
                $this->addError($path, $message);
                $messages[] = $message;
            }
        }

        Flux::toast(variant: 'danger', text: implode(' ', array_unique($messages)));
    }
}
