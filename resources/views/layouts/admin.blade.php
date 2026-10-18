<x-layouts.admin.sidebar :title="$title ?? null">
    <flux:main>
        @include('partials.top-bar')
        {{ $slot }}
    </flux:main>
</x-layouts.admin.sidebar>
