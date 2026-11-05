@php
    $toast = session(\App\Support\Toast::SESSION_KEY);
@endphp

@if (is_array($toast))
    <div
        x-data
        x-init="$nextTick(() => document.dispatchEvent(new CustomEvent('toast-show', {
            detail: {
                duration: 5000,
                slots: { text: @js($toast['text']) },
                dataset: @js(array_filter(['variant' => $toast['variant'] ?? null])),
            },
        })))"
        data-test="flash-toast"
        hidden
    ></div>
@endif
