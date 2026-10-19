{{--
    Live walkthrough L4 to L6: the breadcrumb line, starting with the area,
    and the signed-in person's name and role at the top right with the menu
    for settings and signing out. Shared by the faculty, student and admin
    layouts, so every signed-in page has it. On a phone the name sits in the
    header menu instead.
--}}
@php
    $trail = \App\Support\Navigation\PageTrail::for(request());
    $me = auth()->user();
@endphp

<div class="mb-6 flex items-center justify-between gap-4" data-test="top-bar">
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-zinc-500 dark:text-zinc-400">
            @foreach ($trail['crumbs'] as $i => $crumb)
                <li class="flex min-w-0 items-center gap-1.5">
                    @if ($i > 0)
                        <span aria-hidden="true" class="text-zinc-400 dark:text-zinc-500">›</span>
                    @endif
                    @if ($crumb['url'])
                        <a href="{{ $crumb['url'] }}" wire:navigate class="truncate hover:text-zinc-900 hover:underline dark:hover:text-white {{ $i === 0 ? 'font-medium text-zinc-700 dark:text-zinc-200' : '' }}">{{ $crumb['label'] }}</a>
                    @else
                        <span aria-current="page" class="truncate font-medium text-zinc-900 dark:text-white">{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($me)
        <flux:dropdown position="bottom" align="end" class="hidden shrink-0 lg:block">
            <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1 text-start hover:bg-zinc-100 dark:hover:bg-white/10" data-test="top-bar-user">
                <flux:avatar size="sm" :name="$me->name" :initials="$me->initials()" />
                <span class="leading-tight">
                    <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $me->name }}</span>
                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $trail['role'] }}</span>
                </span>
                <flux:icon.chevron-down variant="micro" class="text-zinc-400" />
            </button>

            <flux:menu>
                <div class="px-2 py-1.5 text-sm leading-tight">
                    <p class="truncate font-medium">{{ $me->name }}</p>
                    <p class="truncate text-zinc-500 dark:text-zinc-400">{{ $me->email }}</p>
                </div>
                <flux:menu.separator />
                <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
