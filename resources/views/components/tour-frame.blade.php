{{-- A screen as the app shows it: the top bar with breadcrumbs and the signed-in person, a slim menu, and the page. --}}
@props(['crumbs' => [], 'user' => null, 'menuTitle' => null, 'menu' => [], 'active' => null])
<div class="overflow-hidden rounded-xl border border-zinc-300 bg-white shadow-sm dark:border-white/15 dark:bg-zinc-900">
    <div class="flex items-center gap-3 border-b border-zinc-200 bg-zinc-100 px-3 py-2 dark:border-white/10 dark:bg-zinc-800">
        <span class="flex gap-1.5" aria-hidden="true"><i class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></i><i class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></i><i class="size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600"></i></span>
        <span class="min-w-0 flex-1 truncate text-xs text-zinc-600 dark:text-zinc-300">{{ implode(' › ', $crumbs) }}</span>
        @if ($user)
            <span class="hidden shrink-0 text-xs text-zinc-500 sm:block dark:text-zinc-400">{{ $user }}</span>
        @endif
    </div>
    <div class="flex">
        @if ($menu)
            <aside class="hidden w-40 shrink-0 border-e border-zinc-200 bg-zinc-50 p-3 text-xs md:block dark:border-white/10 dark:bg-zinc-900">
                <p class="mb-1 px-2 font-semibold text-zinc-500">{{ $menuTitle }}</p>
                @foreach ($menu as $item)
                    <p class="rounded px-2 py-1 {{ $item === $active ? 'bg-white font-medium text-emerald-800 shadow-sm dark:bg-white/10 dark:text-emerald-300' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $item }}</p>
                @endforeach
            </aside>
        @endif
        <div class="min-w-0 flex-1 space-y-3 p-4 text-sm">{{ $slot }}</div>
    </div>
</div>
