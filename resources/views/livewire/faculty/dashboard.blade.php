<div class="flexee-page">

    <x-page-header eyebrow="FLEXEE WORKSPACE" :title="'Welcome, ' . auth()->user()->name"
        description="Manage your courses, firms, quarters, and CF Plus.">
    </x-page-header>

    @if ($courses->isEmpty())

        <flux:card class="space-y-4">

            <flux:heading size="lg">
                No courses yet
            </flux:heading>

            <flux:text>
                Courses are prepared and assigned by an administrator.
            </flux:text>

        </flux:card>
    @else
        <div class="space-y-5">

            {{-- Courses heading --}}
            <div class="flex items-end justify-between gap-4">

                <div>
                    <flux:heading size="xl">
                        Your courses
                    </flux:heading>

                    <flux:text class="mt-1">
                        Open a course to manage its complete simulation workflow.
                    </flux:text>
                </div>

                <flux:badge color="zinc">
                    {{ $courses->count() }}
                    {{ str('course')->plural($courses->count()) }}
                </flux:badge>

            </div>


            {{-- Course grid --}}
            <div class="grid gap-4 lg:grid-cols-2">

                @foreach ($courses as $course)
                    <flux:card class="!p-5">

                        {{-- Course header --}}
                        <div class="flex items-start justify-between gap-4">

                            <div class="min-w-0">

                                <div class="flex flex-wrap items-center gap-2">

                                    <flux:heading size="lg">
                                        {{ $course->name }}
                                    </flux:heading>

                                    <flux:badge
                                        :color="$course->status === 'deployed'
                                                                                                                                                                    ? 'emerald'
                                                                                                                                                                    : 'zinc'">
                                        {{ str($course->status)->title() }}
                                    </flux:badge>

                                </div>

                                <flux:text class="mt-1">
                                    {{ $course->scenario?->name ?? 'CF Manufacturing' }}
                                </flux:text>

                            </div>

                        </div>


                        {{-- Course statistics --}}
                        <div
                            class="mt-5 flex divide-x divide-zinc-200 rounded-lg border border-zinc-200 bg-zinc-50 dark:divide-white/10 dark:border-white/10 dark:bg-white/5">

                            <div class="min-w-0 flex-1 px-4 py-3">

                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                    Quarters
                                </dt>

                                <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                                    {{ $course->quarters_count }}
                                </dd>

                            </div>


                            <div class="min-w-0 flex-1 px-4 py-3">

                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                    Firms
                                </dt>

                                <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
                                    {{ $course->firms_count }}
                                </dd>

                            </div>


                            <div class="min-w-0 flex-1 px-4 py-3">

                                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                                    Mode
                                </dt>

                                <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                                    {{ $course->cf_plus_enabled ? 'CF Plus' : 'CF' }}
                                </dd>

                            </div>

                        </div>


                        {{-- Accounting election status --}}
                        <div
                            class="mt-4 flex items-center justify-between rounded-lg border px-4 py-3
                            {{ $course->accounting_election_enabled
                                ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10'
                                : 'border-zinc-200 bg-zinc-50 dark:border-white/10 dark:bg-white/5' }}">

                            <div class="flex items-center gap-3">

                                <flux:icon name="document-text"
                                    class="size-5
                                        {{ $course->accounting_election_enabled ? 'text-emerald-600' : 'text-zinc-400' }}" />

                                <div>

                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                        Student Accounting Election
                                    </p>

                                    <p class="text-xs text-zinc-500">
                                        {{ $course->accounting_election_enabled
                                            ? 'Student change requests are enabled.'
                                            : 'Default accounting settings are used.' }}
                                    </p>

                                </div>

                            </div>


                            <flux:badge
                                :color="$course->accounting_election_enabled
                                                                                                                                    ? 'emerald'
                                                                                                                                    : 'zinc'">
                                {{ $course->accounting_election_enabled ? 'Enabled' : 'Disabled' }}
                            </flux:badge>

                        </div>


                        {{-- Primary actions --}}
                        <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-white/10">

                            <div class="flex flex-wrap gap-2">

                                <flux:button :href="route('faculty.courses.wizard', $course)" icon="pencil-square">
                                    {{ $course->status === 'deployed' ? 'Review setup' : 'Course setup' }}
                                </flux:button>

                                <flux:button :href="route('faculty.courses.firms', $course)" icon="user-group">
                                    Firms
                                </flux:button>

                                <flux:button :href="route('faculty.courses.students', $course)" icon="users">
                                    Participants
                                </flux:button>

                                <flux:button :href="route('faculty.courses.quarters', $course)" icon="calendar-days">
                                    Quarters
                                </flux:button> 

                            </div>


                            {{-- Secondary actions --}}
                            <div class="mt-2 flex flex-wrap gap-2">

                                 <flux:button :href="route('faculty.courses.quarter-results', $course)" icon="chart-bar"> 
                                    Quarter Results 
                                </flux:button>

                                <flux:button
                                    :href="route(
                                        'faculty.courses.accounting-election-requests',
                                        $course
                                    )"
                                    icon="document-text">
                                    Accounting Elections
                                </flux:button>


                                @if ($course->cf_plus_enabled)
                                    <flux:button
                                        :href="route(
                                            'faculty.courses.cf-plus-auction',
                                            $course
                                        )"
                                        icon="scale">
                                        CF Plus
                                    </flux:button>
                                @endif

                            </div>

                        </div>

                    </flux:card>
                @endforeach

            </div>

        </div>

    @endif

</div>
