<?php

use Hata\Livewire\Admin\EntryEditPage;

new class extends EntryEditPage {
    public function render(): mixed
    {
        return $this->view()->title(__('content.form.page_title', [
            'type' => $this->contentType->label(),
            'title' => $this->entry->title,
        ]));
    }
}; ?>

@include('partials.admin.content.entry-form', [
    'entry' => $this->entry,
    'groupEntries' => $this->groupRecords->whereNull('deleted_at')->push($this->entry)->values(),
    'trashedEntries' => $this->groupRecords->whereNotNull('deleted_at')->keyBy('locale'),
    'fromId' => $this->entryId,
    'localeChoices' => $this->localeChoices,
])
