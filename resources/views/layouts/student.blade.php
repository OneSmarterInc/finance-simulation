<x-layouts::student.sidebar :title="$title ?? null">
    <flux:main>
        @include('partials.top-bar')
        @include('partials.practice-view-banner')
        {{ $slot }}
    </flux:main>
</x-layouts::student.sidebar>
