<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-zinc-50 text-zinc-950 antialiased dark:bg-zinc-950 dark:text-zinc-100">

    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('student.courses') }}" wire:navigate />

            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>

            <flux:sidebar.group :heading="__('Workspace')" class="grid">
                <flux:sidebar.item icon="home" :href="route('student.courses')"
                    :current="request()->routeIs('student.courses')" wire:navigate>
                    {{ __('My courses') }}
                </flux:sidebar.item>
                <flux:sidebar.item icon="book-open-text" :href="route('guide')"
                    :current="request()->routeIs('guide')" wire:navigate>
                    {{ __('Guide') }}
                </flux:sidebar.item>
            </flux:sidebar.group>

            {{-- The firm's pages. Faculty viewing a firm get the faculty layout instead. --}}
            @php
                $currentFirm = request()->route('firm');
                $currentFirm = $currentFirm instanceof \App\Models\Firm ? $currentFirm->loadMissing('course') : null;
                $firmPaid = $currentFirm && auth()->user()?->student
                    ? \App\Services\Students\StudentAccess::isPaid(auth()->user()->student, $currentFirm->course)
                    : false;
            @endphp

            @if ($currentFirm && $firmPaid)
                @php
                    $firmStatus = \App\Services\Courses\CourseStatus::for($currentFirm->course);
                    $activeQuarter = $firmStatus->active();
                    $electionQuarter = $activeQuarter ?? $firmStatus->quarters->where('quarter_number', '>=', 4)->last();
                @endphp

                <flux:sidebar.group :heading="$currentFirm->name" class="grid">
                    <flux:sidebar.item icon="layout-grid" :href="route('firms.dashboard', $currentFirm)"
                        :current="request()->routeIs('firms.dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    @if ($activeQuarter)
                        <flux:sidebar.item icon="pencil-square"
                            :href="route('student.decisions.workspace', ['firm' => $currentFirm, 'quarter' => $activeQuarter])"
                            :current="request()->routeIs('student.decisions.workspace')"
                            :badge="'Q'.$activeQuarter->quarter_number" wire:navigate>
                            {{ __('Decisions') }}
                        </flux:sidebar.item>
                    @else
                        <flux:sidebar.item icon="pencil-square" class="pointer-events-none opacity-50">
                            {{ __('Decisions') }}
                        </flux:sidebar.item>
                    @endif

                    @if ($currentFirm->course->accounting_election_enabled && $electionQuarter)
                        <flux:sidebar.item icon="document-text"
                            :href="route('student.accounting-election', ['firm' => $currentFirm, 'quarter' => $electionQuarter])"
                            :current="request()->routeIs('student.accounting-election')" wire:navigate>
                            {{ __('Accounting elections') }}
                        </flux:sidebar.item>
                    @endif

                    <flux:sidebar.item icon="clock" :href="route('firms.history', $currentFirm)"
                        :current="request()->routeIs('firms.history')" wire:navigate>
                        {{ __('History') }}
                    </flux:sidebar.item>

                    @if ($currentFirm->course->cf_plus_enabled)
                        @if ($firmStatus->evaluationOpen())
                            <flux:sidebar.item icon="presentation-chart-bar" :href="route('student.cf-plus.evaluation', $currentFirm)"
                                :current="request()->routeIs('student.cf-plus.evaluation')" wire:navigate>
                                {{ __('Evaluation stage') }}
                            </flux:sidebar.item>
                        @else
                            <flux:sidebar.item icon="presentation-chart-bar" :href="route('student.cf-plus.evaluation', $currentFirm)"
                                :current="request()->routeIs('student.cf-plus.evaluation')"
                                :badge="'Q'.$firmStatus->evaluationStart()" class="opacity-60" wire:navigate>
                                {{ __('Evaluation stage') }}
                            </flux:sidebar.item>
                        @endif
                    @endif
                </flux:sidebar.group>
            @endif

        </flux:sidebar.nav>

        <flux:spacer />

    </flux:sidebar>

    {{-- Mobile User Menu --}}
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>

                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">

                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">
                                    {{ auth()->user()->name }}
                                </flux:heading>

                                <flux:text class="truncate">
                                    {{ auth()->user()->email }}
                                </flux:text>
                                    <flux:text class="truncate">{{ \App\Support\Navigation\PageTrail::for(request())['role'] }}</flux:text>
                            </div>

                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf

                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>

            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
        <flux:toast.group>
            <flux:toast />
        </flux:toast.group>
    @endpersist

    @fluxScripts
</body>

</html>
