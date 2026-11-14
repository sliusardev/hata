<?php

use App\Models\Entry;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Placeholder until the record form arrives (Task 9): authorizes and links back to the list.
 */
new class extends Component {
    #[Locked]
    public string $type = '';

    #[Locked]
    public Entry $entry;

    public function mount(string $type, Entry $entry): void
    {
        abort_unless($entry->type === $type, 404);

        $this->authorize('view', $entry);

        $this->type = $type;
        $this->entry = $entry;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->entry->title);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.content.index', $type)" wire:navigate>
        {{ __('content.list.back') }}
    </flux:button>

    <flux:heading size="xl" level="1">{{ $entry->title }}</flux:heading>
</section>
