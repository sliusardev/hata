<flux:heading level="2" class="mb-4">Personal card</flux:heading>
<div class="grid gap-6 sm:grid-cols-2">
    <flux:field>
        <flux:label>Birthday</flux:label>
        <flux:input type="date" :attributes="$field('birthday')" data-test="personal-birthday" />
        <flux:error :name="$errorName('birthday')" />
    </flux:field>
    <flux:field>
        <flux:label>Gender</flux:label>
        <flux:input :attributes="$field('gender')" data-test="personal-gender" />
        <flux:error :name="$errorName('gender')" />
    </flux:field>
</div>
