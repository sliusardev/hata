@php($widgets = \Hata\Admin::dashboard()->for(auth()->user()))

<x-hata::layouts.app :title="__('hata::navigation.dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-4">
        @if ($widgets === [])
            <x-hata::admin.empty-state
                icon="squares-2x2"
                :heading="__('hata::dashboard.empty.heading')"
                :description="__('hata::dashboard.empty.text')"
            />
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-6" data-test="dashboard-grid">
                @foreach ($widgets as $widget)
                    <div class="{{ $widget->cellClass() }}" data-test="dashboard-widget" data-widget="{{ $widget->key }}">
                        <livewire:dynamic-component :is="$widget->component" :key="'widget-'.$widget->key" lazy.bundle />
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-hata::layouts.app>
