<flux:heading level="2" class="mb-4">Subtitle card</flux:heading>
<flux:field>
    <flux:label>Subtitle</flux:label>
    <flux:input :attributes="$field('text')" data-test="subtitle-{{ $key }}" />
    <flux:error :name="$errorName('text')" />
</flux:field>
