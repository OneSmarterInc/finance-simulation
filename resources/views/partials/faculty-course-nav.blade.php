{{--
    Course pages as a sidebar group (walkthrough item 18): the course name as
    the heading, a way to switch course, the current quarter's state beside
    Quarters and a waiting count beside Accounting methods.
--}}
@php
    $navCourse = request()->route('course');
    if (! $navCourse instanceof \App\Models\Course) {
        $navFirm = request()->route('firm');
        $navCourse = $navFirm instanceof \App\Models\Firm ? $navFirm->course : null;
    }
    $navFaculty = auth()->user()?->faculty;
@endphp

@if ($navCourse && $navFaculty)
    @php
        $navStatus = \App\Services\Courses\CourseStatus::for($navCourse);
        $navCurrent = $navStatus->current();
        $navPending = $navCourse->accounting_election_enabled ? $navStatus->pendingRequests() : 0;
        $navOthers = $navFaculty->teachingCourses()->reject(fn ($c) => $c->id === $navCourse->id);
        $navRoute = request()->route()?->getName();
        $navSwitchable = in_array($navRoute, [
            'faculty.courses.wizard', 'faculty.courses.firms', 'faculty.courses.students', 'faculty.courses.quarters',
            'faculty.courses.quarter-results', 'faculty.courses.explanations', 'faculty.courses.practice', 'faculty.courses.accounting-election-requests', 'faculty.courses.cf-plus-auction',
        ], true) ? $navRoute : 'faculty.courses.quarters';
    @endphp

    <flux:sidebar.group class="grid min-w-0 grid-cols-1">
        <div class="mb-1 flex min-w-0 items-start justify-between gap-2 px-1">
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $navCourse->name }}</p>
                <p class="truncate text-xs text-zinc-500">{{ \App\Support\Product::name($navCourse) }}</p>
            </div>
            @if ($navOthers->isNotEmpty())
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="xs" variant="ghost" icon="chevrons-up-down" aria-label="Switch course" />
                    <flux:menu>
                        @foreach ($navOthers as $other)
                            <flux:menu.item :href="route($other->status === 'deployed' ? $navSwitchable : 'faculty.courses.wizard', $other)" wire:navigate>
                                {{ $other->name }}
                            </flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>

        <flux:sidebar.item icon="pencil-square" :href="route('faculty.courses.wizard', $navCourse)"
            :current="request()->routeIs('faculty.courses.wizard')" wire:navigate>
            {{ $navCourse->status === 'deployed' ? __('Review setup') : __('Course setup') }}
        </flux:sidebar.item>

        <flux:sidebar.item icon="user-group" :href="route('faculty.courses.firms', $navCourse)"
            :current="request()->routeIs('faculty.courses.firms') || request()->routeIs('firms.history') || request()->routeIs('firms.dashboard')" wire:navigate>
            {{ __('Firms') }}
        </flux:sidebar.item>

        <flux:sidebar.item icon="users" :href="route('faculty.courses.students', $navCourse)"
            :current="request()->routeIs('faculty.courses.students')" wire:navigate>
            {{ __('Students') }}
        </flux:sidebar.item>

        <flux:sidebar.item icon="calendar-days" :href="route('faculty.courses.quarters', $navCourse)"
            :current="request()->routeIs('faculty.courses.quarters')"
            :badge="$navCurrent ? 'Q'.$navCurrent->quarter_number : null"
            wire:navigate>
            {{ __('Quarters') }}
        </flux:sidebar.item>

        <flux:sidebar.item icon="chart-bar" :href="route('faculty.courses.quarter-results', $navCourse)"
            :current="request()->routeIs('faculty.courses.quarter-results')" wire:navigate>
            {{ __('Quarter results') }}
        </flux:sidebar.item>

        {{-- U11: the teams' explanations of each quarter. --}}
        @unless ($navCourse->is_practice)
            <flux:sidebar.item icon="pencil" :href="route('faculty.courses.explanations', $navCourse)"
                :current="request()->routeIs('faculty.courses.explanations')" wire:navigate>
                {{ __('Team explanations') }}
            </flux:sidebar.item>
        @endunless

        {{-- U9: rehearse the course with the three reference teams. Owner only, current engine only. --}}
        @if (! $navCourse->is_practice && $navCourse->faculty_id === $navFaculty->id && $navCourse->engineVersion() === 2)
            <flux:sidebar.item icon="play-circle" :href="route('faculty.courses.practice', $navCourse)"
                :current="request()->routeIs('faculty.courses.practice')" wire:navigate>
                {{ __('Practice run') }}
            </flux:sidebar.item>
        @endif

        @if ($navCourse->accounting_election_enabled)
            <flux:sidebar.item icon="document-text" :href="route('faculty.courses.accounting-election-requests', $navCourse)"
                :current="request()->routeIs('faculty.courses.accounting-election-requests')"
                :badge="$navPending > 0 ? (string) $navPending : null" wire:navigate>
                {{ __('Accounting methods') }}
            </flux:sidebar.item>
        @endif

        @if ($navCourse->cf_plus_enabled)
            <flux:sidebar.item icon="scale" :href="route('faculty.courses.cf-plus-auction', $navCourse)"
                :current="request()->routeIs('faculty.courses.cf-plus-auction')"
                :badge="$navStatus->evaluationOpen() ? null : 'Q'.$navStatus->evaluationStart()"
                wire:navigate>
                {{ __('Evaluation stage') }}
            </flux:sidebar.item>
        @endif
    </flux:sidebar.group>
@endif
