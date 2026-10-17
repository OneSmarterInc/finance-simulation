<div class="flexee-page">

    <x-page-header
        :eyebrow="$course->name"
        title="Firm management"
        description="Create firms, assign students, and monitor inherited warm-up history."
    >
        <x-slot:actions>
            <flux:button
                :href="route('dashboard')"
                icon="arrow-left"
            >
                Back to dashboard
            </flux:button>
        </x-slot:actions>
    </x-page-header>


    {{-- Validation / Error messages --}}
    @if ($errors->any())
        <flux:callout
            variant="danger"
            icon="exclamation-triangle"
            heading="This action needs attention"
            role="alert"
        >
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif


    {{-- ========================================================= --}}
    {{-- COURSE PARTICIPANT SUMMARY --}}
    {{-- ========================================================= --}}

    <section class="space-y-4">

        <div>
            <flux:heading size="lg" level="2">
                Course participants
            </flux:heading>

            <flux:text class="mt-1">
                Monitor student registration and firm assignment for this course.
            </flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">

            {{-- Enrolled --}}
            <flux:card>
                <div class="flex items-center gap-4">

                    <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                        <flux:icon.users class="size-5" />
                    </div>

                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Enrolled
                        </div>

                        <div class="mt-1 font-mono text-2xl font-semibold tabular-nums">
                            {{ $enrolledCount }}
                        </div>
                    </div>

                </div>
            </flux:card>


            {{-- Assigned --}}
            <flux:card>
                <div class="flex items-center gap-4">

                    <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                        <flux:icon.user-plus class="size-5" />
                    </div>

                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Assigned to firm
                        </div>

                        <div class="mt-1 font-mono text-2xl font-semibold tabular-nums">
                            {{ $assignedCount }}
                        </div>
                    </div>

                </div>
            </flux:card>


            {{-- Pending --}}
            <flux:card>
                <div class="flex items-center gap-4">

                    <div class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                        <flux:icon.clock class="size-5" />
                    </div>

                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Pending assignment
                        </div>

                        <div class="mt-1 font-mono text-2xl font-semibold tabular-nums">
                            {{ $pendingCount }}
                        </div>
                    </div>

                </div>
            </flux:card>

        </div>

    </section>


    {{-- ========================================================= --}}
    {{-- CREATE FIRM + ENROLL / ASSIGN --}}
    {{-- ========================================================= --}}

    <div class="grid gap-6 lg:grid-cols-2">


        {{-- ===================================================== --}}
        {{-- CREATE FIRM --}}
        {{-- ===================================================== --}}

        <flux:card class="space-y-5">

            <div>
                <flux:heading size="lg" level="2">
                    Create firm
                </flux:heading>

                <flux:text class="mt-1">
                    Create a firm that will participate in this course.
                </flux:text>
            </div>

            <div class="space-y-4">

                <flux:input
                    wire:model="code"
                    label="Firm code"
                    placeholder="e.g. FIRM-01"
                />

                <flux:input
                    wire:model="name"
                    label="Firm name"
                    placeholder="e.g. Alpha Manufacturing"
                />

            </div>

            <div class="flex justify-start border-t border-zinc-200 pt-4 dark:border-white/10">

                <flux:button
                    wire:click="createFirm"
                    wire:loading.attr="disabled"
                    wire:target="createFirm"
                    icon="plus"
                    variant="primary"
                >
                    Create
                </flux:button>

            </div>

        </flux:card>


        {{-- ===================================================== --}}
        {{-- ENROLL + ASSIGN --}}
        {{-- ===================================================== --}}

        <flux:card class="space-y-5">

            <div>
                <flux:heading size="lg" level="2">
                    Enroll and assign student
                </flux:heading>

                <flux:text class="mt-1">
                    Assign a student who is registered for this course to a firm.
                </flux:text>
            </div>


            {{-- No enrolled students --}}
            @if ($enrolledCount === 0)

                <flux:callout
                    icon="information-circle"
                    heading="No students enrolled yet"
                    text="Students registered for this course will appear here once they complete registration."
                />

            @else

                <div class="space-y-4">

                    {{-- Student --}}
                    <flux:select
                        wire:model="studentId"
                        label="Student"
                    >
                        <flux:select.option value="">
                            Select student
                        </flux:select.option>

                        @foreach ($students as $student)

                            <flux:select.option :value="$student->id">

                                {{ $student->user->name }}
                                ·
                                {{ $student->user->email }}

                            </flux:select.option>

                        @endforeach

                    </flux:select>


                    {{-- Firm --}}
                    <flux:select
                        wire:model="firmId"
                        label="Firm"
                    >

                        <flux:select.option value="">
                            Select firm
                        </flux:select.option>

                        @foreach ($firms as $firm)

                            <flux:select.option :value="$firm->id">

                                {{ $firm->code }}
                                ·
                                {{ $firm->name }}

                            </flux:select.option>

                        @endforeach

                    </flux:select>

                </div>


                {{-- Assign button --}}
                <div class="flex justify-start border-t border-zinc-200 pt-4 dark:border-white/10">

                    <flux:button
                        wire:click="enrollAndAssign"
                        wire:loading.attr="disabled"
                        wire:target="enrollAndAssign"
                        icon="user-plus"
                        variant="primary"
                    >
                        Assign
                    </flux:button>

                </div>

            @endif

        </flux:card>

    </div>


    {{-- ========================================================= --}}
    {{-- DEMO PARTICIPANTS --}}
    {{-- ========================================================= --}}

    @if ($showDemoImport)

        <flux:card class="space-y-5">

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                <div>

                    <div class="flex items-center gap-2">

                        <flux:icon.sparkles class="size-5 text-amber-500" />

                        <flux:heading size="lg" level="2">
                            Demo participants
                        </flux:heading>

                    </div>

                    <flux:text class="mt-1">
                        Import the predefined demo students for demonstration purposes.
                    </flux:text>

                </div>

            </div>


            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-white/10 dark:bg-white/5">

                <div class="flex items-start gap-3">

                    <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">

                        <flux:icon.user-group class="size-5" />

                    </div>

                    <div class="min-w-0">

                        <flux:heading size="sm">
                            Quick demo setup
                        </flux:heading>

                        <flux:text class="mt-1 text-sm">

                            Existing demo accounts will be registered for this course
                            and assigned automatically, with a maximum of
                            <strong>2 students per firm</strong>.

                        </flux:text>

                        <div class="mt-3 flex flex-wrap gap-2">

                            <flux:badge color="zinc">

                                {{ $firms->count() }}
                                {{ $firms->count() === 1 ? 'firm' : 'firms' }}

                            </flux:badge>

                            <flux:badge color="zinc">

                                {{ $importableDemoCount }}
                                {{ $importableDemoCount === 1 ? 'demo student' : 'demo students' }}
                                available

                            </flux:badge>

                        </div>

                    </div>

                </div>

            </div>


            <div class="flex justify-start border-t border-zinc-200 pt-4 dark:border-white/10">

                <flux:button
                    wire:click="importDemoStudents"
                    wire:loading.attr="disabled"
                    wire:target="importDemoStudents"
                    icon="arrow-down-tray"
                    variant="primary"
                >

                    Import demo students

                </flux:button>

            </div>

        </flux:card>

    @endif


    {{-- ========================================================= --}}
    {{-- CURRENT FIRMS --}}
    {{-- ========================================================= --}}

    <section class="space-y-4">

        <div>

            <flux:heading size="lg" level="2">
                Current firms
            </flux:heading>

            <flux:text class="mt-1">
                Review firm membership and inherited warm-up status.
            </flux:text>

        </div>


        @forelse ($firms as $firm)

            <flux:card class="space-y-4">

                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                    <div class="min-w-0">

                        <div class="flex flex-wrap items-center gap-2">

                            <flux:heading size="lg">
                                {{ $firm->code }}
                            </flux:heading>

                            <flux:badge
                                :color="$firm->warmup_status === \App\Models\Firm::WARMUP_INITIALIZED
                                    ? 'emerald'
                                    : 'zinc'"
                            >
                                {{ str($firm->warmup_status)->replace('_', ' ')->title() }}
                            </flux:badge>

                        </div>

                        <flux:text class="mt-1">
                            {{ $firm->name }}
                        </flux:text>

                    </div>


                    <flux:button
                        :href="route('firms.history', $firm)"
                        icon="document-chart-bar"
                    >
                        Inherited history
                    </flux:button>

                </div>


                <div class="grid gap-4 border-t border-zinc-200 pt-4 dark:border-white/10 sm:grid-cols-3">

                    {{-- Students --}}
                    <div>

                        <div class="text-xs uppercase tracking-wide text-zinc-500">
                            Students
                        </div>

                        <div class="mt-1 font-mono text-lg font-semibold tabular-nums">
                            {{ $firm->memberships_count }}
                        </div>

                    </div>


                    {{-- Warm-up --}}
                    <div>

                        <div class="text-xs uppercase tracking-wide text-zinc-500">
                            Warm-up
                        </div>

                        <div class="mt-1 font-medium">
                            {{ str($firm->warmup_status)->replace('_', ' ')->title() }}
                        </div>

                    </div>


                    {{-- Inherited quarters --}}
                    <div>

                        <div class="text-xs uppercase tracking-wide text-zinc-500">
                            Inherited quarters
                        </div>

                        <div class="mt-1 font-mono text-lg font-semibold tabular-nums">
                            {{ $firm->engine_results_count }}
                        </div>

                    </div>

                </div>


                {{-- Warm-up status --}}
                @if ($firm->warmup_status === \App\Models\Firm::WARMUP_INITIALIZED)

                    <flux:callout
                        variant="success"
                        icon="check-circle"
                        heading="Warm-up history initialized"
                        text="Inherited Q1-Q3 history is available for this firm."
                    />

                @elseif ($firm->warmup_status === \App\Models\Firm::WARMUP_FAILED)

                    <flux:callout
                        variant="danger"
                        icon="exclamation-triangle"
                        heading="Warm-up initialization failed"
                        text="Review the firm state before opening the simulation."
                    />

                @else

                    <flux:callout
                        icon="clock"
                        heading="Warm-up pending"
                        text="Inherited history will become available after firm initialization."
                    />

                @endif

            </flux:card>

        @empty

            <flux:card>

                <div class="flex items-start gap-3">

                    <div class="flex size-10 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300">

                        <flux:icon.building-office />

                    </div>

                    <div>

                        <flux:heading size="lg">
                            No firms have been created
                        </flux:heading>

                        <flux:text class="mt-1">
                            Create the first firm above to begin assigning students.
                        </flux:text>

                    </div>

                </div>

            </flux:card>

        @endforelse

    </section>


    {{-- ========================================================= --}}
    {{-- LOADING --}}
    {{-- ========================================================= --}}

    <div
        wire:loading
        role="status"
        class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400"
    >

        <flux:icon.loading class="size-4" />

        Updating firms...

    </div>

</div>