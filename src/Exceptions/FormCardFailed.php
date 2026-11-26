<?php

namespace Hata\Exceptions;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Whatever a form card's `save()` threw, carried out of the form's save action untouched by the action's own error
 * handling: an action catches a unique violation or a validation error of its record's write, and a module's
 * failure must never be reported as the record's (a taken slug, a taken language). The transaction rolls back as for
 * any exception; the form then shows a `ValidationException` under the card (`$card`, null when it is not known) and
 * throws anything else as it was (`getPrevious()`).
 */
final class FormCardFailed extends RuntimeException
{
    public function __construct(public readonly ?string $card, Throwable $previous)
    {
        parent::__construct(($card !== null ? "The form card [{$card}] failed to save: " : 'A form card failed to save: ').$previous->getMessage(), 0, $previous);
    }

    /**
     * Runs a card's save, wrapping what it throws (a `FormCardFailed` passes as it is).
     *
     * @throws self
     */
    public static function guard(Closure $save, ?string $card = null): void
    {
        try {
            $save();
        } catch (FormCardFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new self($card, $exception);
        }
    }

    /**
     * What the card threw.
     */
    public function original(): Throwable
    {
        /** @var Throwable */
        return $this->getPrevious();
    }
}
