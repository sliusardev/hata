<flux:heading level="2" class="mb-4">Personal card</flux:heading>
<div class="grid gap-6 sm:grid-cols-2">
    <flux:field>
        <flux:label>Birthday</flux:label>
        <flux:input type="date" :attributes="$field('birthday')" data-test="personal-birthday" />
        <flux:error :name="$errorName('birthday')" />
    </flux:field>
    <flux:field>
        <flux:label>Gender</flux:label>
        {{-- A native select, marked from $values: on registration the browser ignores a value on the select itself. --}}
        <flux:select :attributes="$field('gender')" data-test="personal-gender">
            <flux:select.option value="">-</flux:select.option>
            @foreach (['f' => 'Female', 'm' => 'Male', 'x' => 'Other'] as $genderValue => $genderLabel)
                <flux:select.option :value="$genderValue" :selected="($values['gender'] ?? '') === $genderValue">{{ $genderLabel }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error :name="$errorName('gender')" />
    </flux:field>
</div>
