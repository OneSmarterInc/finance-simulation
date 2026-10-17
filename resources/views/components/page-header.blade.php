@props(['eyebrow' => null, 'title', 'description' => null])

<header {{ $attributes->class('border-b border-zinc-200 pb-5 dark:border-white/10') }}>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0 space-y-1">
            @if($eyebrow)
                <p class="flexee-section-label">{{ $eyebrow }}</p>
            @endif
            <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
            @if($description)
                <flux:text class="max-w-3xl">{{ $description }}</flux:text>
            @endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
</header>
