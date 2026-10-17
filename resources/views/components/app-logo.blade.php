@props([
    'sidebar' => false,
])

@if($sidebar)
    {{-- Two lines so the full product name (N1) fits the sidebar without truncating. --}}
    <a {{ $attributes->class('flex min-w-0 items-center gap-2 px-2 py-1') }} data-flux-sidebar-brand aria-label="{{ \App\Support\Product::NAME }}">
        <span class="flex aspect-square size-8 shrink-0 items-center justify-center rounded-md bg-emerald-700 text-white dark:bg-emerald-400 dark:text-emerald-950">
            <x-app-logo-icon class="size-5" />
        </span>
        <span class="min-w-0 leading-tight in-data-flux-sidebar-collapsed-desktop:hidden">
            <span class="block text-sm font-semibold text-zinc-800 dark:text-zinc-100">Flexee</span>
            <span class="block text-xs text-zinc-500 dark:text-zinc-400">Financial Accounting</span>
        </span>
    </a>
@else
    <flux:brand :name="\App\Support\Product::NAME" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-emerald-700 text-white dark:bg-emerald-400 dark:text-emerald-950">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
