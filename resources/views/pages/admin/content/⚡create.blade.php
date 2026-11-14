<?php

use App\Models\Entry;
use App\Support\Content\ContentType;
use App\Support\Content\ContentTypes;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Placeholder until the record form arrives (Task 9): authorizes and links back to the list.
 */
new class extends Component {
    #[Locked]
    public string $type = '';

    public function mount(string $type): void
    {
        $this->authorize('create', [Entry::class, $type]);

        $this->type = $type;
    }

    public function render(): mixed
    {
        return $this->view()->title($this->contentType->createLabel());
    }

    #[Computed]
    public function contentType(): ContentType
    {
        return app(ContentTypes::class)->get($this->type);
    }
}; ?>

<section class="w-full space-y-6">
    <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('admin.content.index', $type)" wire:navigate>
        {{ __('content.list.back') }}
    </flux:button>

    <flux:heading size="xl" level="1">{{ $this->contentType->createLabel() }}</flux:heading>
</section>
