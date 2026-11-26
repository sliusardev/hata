<?php

namespace Hata\Livewire\Admin\Concerns;

use Hata\Support\Admin\Forms\FormCard;
use Hata\Support\Admin\Forms\FormCards;
use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtensions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
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
}
