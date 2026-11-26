@props(['column', 'record', 'context'])

<flux:table.cell :align="$column->align" :class="$column->hideClass()">
    @if ($column->value instanceof \Closure)
        {{ ($column->value)($record, $context) }}
    @else
        @include($column->value, ['record' => $record, 'context' => $context])
    @endif
</flux:table.cell>
