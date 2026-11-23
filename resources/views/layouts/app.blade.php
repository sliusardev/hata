<x-hata::layouts.app.sidebar :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
</x-hata::layouts.app.sidebar>
