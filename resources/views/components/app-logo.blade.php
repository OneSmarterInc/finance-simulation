@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :name="\App\Support\Product::NAME" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-emerald-700 text-white dark:bg-emerald-400 dark:text-emerald-950">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="\App\Support\Product::NAME" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-emerald-700 text-white dark:bg-emerald-400 dark:text-emerald-950">
            <x-app-logo-icon class="size-5" />
        </x-slot>
    </flux:brand>
@endif
