{{--
    The registered form cards of one area of a form (Hata\Support\Admin\Forms\FormCards), each in Hata's card frame.

    Receives:
    - $cards     the cards the form shows (FormCards::for()), in order;
    - $area      the area drawn here: main or side;
    - $frame     the card frame's classes, the form's own;
    - $values    every card's values, by card key;
    - $livewire  whether the inputs bind with wire:model (else they are posted by name, with the old input);
    - $readOnly  whether the form is read-only: the card's inputs are disabled;
    - $bare      whether to draw the card without a frame (the profile and registration forms, whose fields are bare).

    A card's view gets $key, $field (Closure(string): ComponentAttributeBag), $errorName (Closure(string): string) and
    $values (its own).
--}}
@php
    $formExtensions = app(Hata\Support\Admin\Forms\FormExtensions::class);
@endphp
@foreach ($cards as $formCard)
    @continue($formCard->area !== $area)
    @php
        $cardKey = $formCard->key;
    @endphp
    <div @class([$frame.' p-5' => ! ($bare ?? false)]) wire:key="form-card-{{ $cardKey }}" data-test="form-card-{{ $cardKey }}">
        <fieldset class="min-w-0" @disabled($readOnly ?? false)>
            @include(app($formCard->extension)->view(), [
                'key' => $cardKey,
                'field' => fn (string $field) => $formExtensions->field($cardKey, $field, $livewire),
                'errorName' => fn (string $field): string => "extensions.{$cardKey}.{$field}",
                'values' => $values[$cardKey] ?? [],
            ])
        </fieldset>
    </div>
@endforeach
