<div class="space-y-6">
    <x-page-header eyebrow="FLEXEE ADMINISTRATION" title="Course Preparation" :description="$course ? 'Configure course: ' . $course->name : 'Create a new simulation course.'">
        <x-slot:actions>
            <flux:button :href="route('admin.courses')" variant="ghost" icon="arrow-left" wire:navigate>
                Simulation Courses
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="flex items-center gap-2 text-sm">
        <flux:badge color="blue">
            Step {{ $step }} of 6
        </flux:badge>

        <span class="text-zinc-500 dark:text-zinc-400">
            {{ match ($step) {
                1 => 'Setup',
                2 => 'Schedule',
                3 => 'Decisions',
                4 => 'Industry',
                5 => 'Faculty',
                6 => 'Deploy',
                default => 'Setup',
            } }}
        </span>
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" heading="This step needs attention." role="alert">
            <ul class="list-disc pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    {{-- ============================================================
         STEP 1 — SETUP
         ============================================================ --}}
    @if ($step === 1)

        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Course Setup
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Configure the core course details and assign the operational Faculty.
                </p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <flux:input wire:model="name" label="Course Name" />

                <flux:select wire:model="institution_id" label="Institution">
                    <option value="">Select institution</option>

                    @foreach ($institutions as $institution)
                        <option value="{{ $institution->id }}">
                            {{ $institution->name }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="faculty_id" label="Operational Faculty">
                    <option value="">Select Faculty</option>

                    @foreach ($faculties as $faculty)
                        <option value="{{ $faculty->id }}">
                            {{ $faculty->user->name }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="scenario_id" label="Scenario">
                    <option value="">Select scenario</option>

                    @foreach ($scenarios as $scenario)
                        <option value="{{ $scenario->id }}">
                            {{ $scenario->name }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="starts_on" type="date" label="Starts On" />

                <flux:input wire:model="ends_on" type="date" label="Ends On" />

                <flux:select wire:model="payment_mode" label="Payment Mode">
                    <option value="standard">Standard</option>
                </flux:select>
            </div>

            <div class="mt-8 space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-white">
                        CF Plus
                    </h3>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Enable CF Plus configuration for this course if required.
                    </p>
                </div>

                <flux:checkbox wire:model="cf_plus_enabled" label="Enable CF Plus" />

                @if ($cf_plus_enabled)
                    <div class="grid gap-6 md:grid-cols-3">
                        <flux:input wire:model="build_quarters" type="number" min="0" label="Build Quarters" />

                        <flux:input wire:model="adversarial_quarters" type="number" min="0"
                            label="Adversarial Quarters" />

                        <flux:input wire:model="post_bid_quarters" type="number" min="0"
                            label="Post-Bid Quarters" />
                    </div>
                @endif
            </div>

            <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:checkbox wire:model="accounting_election_enabled" label="Enable Accounting Elections" />

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Allows students to participate in the Accounting Elections workflow.
                </p>
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="saveSetup" variant="primary" icon="arrow-right">
                Save & Continue
            </flux:button>
        </div>

        {{-- ============================================================
         STEP 2 — SCHEDULE
         ============================================================ --}}
    @elseif($step === 2)
        <form wire:submit="saveSchedule" class="space-y-6">

            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">

                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Course Schedule
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Configure the quarter schedule for this course.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <flux:input class="w-40" wire:model="quarter_count" type="number" min="4"
                        label="Configured Quarters" />

                    <flux:button type="button" wire:click="buildScheduleRows" icon="table-cells">
                        Generate Rows
                    </flux:button>
                </div>

                @if ($cf_plus_enabled)
                    <flux:callout class="mt-6" variant="warning" icon="information-circle"
                        heading="CF Plus schedule requirement"
                        text="CF Plus needs 3 warm-up + {{ $build_quarters }} build + {{ $adversarial_quarters }} adversarial + {{ $post_bid_quarters }} post-bid quarters." />
                @endif

                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700 text-left">
                                <th class="p-3">Quarter</th>
                                <th class="p-3">Start</th>
                                <th class="p-3">End</th>
                                <th class="p-3">Decision Deadline</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($quarters as $index => $quarter)
                                <tr class="border-b border-zinc-200 dark:border-zinc-800">

                                    <td class="p-3 font-medium">
                                        Q{{ $quarter['quarter_number'] }}

                                        @if ($quarter['quarter_number'] <= 3)
                                            <span class="block text-xs font-normal text-zinc-500">
                                                Warm-up / reserved
                                            </span>
                                        @elseif($quarter['quarter_number'] === 4)
                                            <span
                                                class="block text-xs font-normal text-emerald-700 dark:text-emerald-400">
                                                Student control begins
                                            </span>
                                        @endif
                                    </td>

                                    <td class="p-3">
                                        <input wire:model="quarters.{{ $index }}.starts_on" type="date"
                                            class="rounded-md border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900">
                                    </td>

                                    <td class="p-3">
                                        <input wire:model="quarters.{{ $index }}.ends_on" type="date"
                                            class="rounded-md border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900">
                                    </td>

                                    <td class="p-3">
                                        <input wire:model="quarters.{{ $index }}.decision_deadline_at"
                                            type="date"
                                            class="rounded-md border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900">
                                    </td>

                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="p-8 text-center text-sm text-zinc-500">
                                        No quarter rows generated yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="flex justify-between">
                <flux:button type="button" wire:click="$set('step', 1)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="submit" icon="arrow-right" variant="primary">
                    Save & Continue
                </flux:button>
            </div>

        </form>

        {{-- ============================================================
         STEP 3 — DECISIONS
         ============================================================ --}}
    @elseif($step === 3)
        <form wire:submit="saveEnablements" class="space-y-6">

            <div
                class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">

                <div class="mb-6">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Decision Enablements
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Configure which decision categories students can control in each student quarter.
                    </p>
                </div>

                <flux:callout variant="success" icon="information-circle"
                    heading="Student control begins at Quarter 4"
                    text="Q1-Q3 are reserved for system-generated warm-up history. Quarter 4 requires Forecasting, Procurement (sourcing), and Production. Operating categories remain enabled after activation. Certification is independent." />

                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[900px] text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="p-3 text-left">
                                    Category
                                </th>

                                @foreach (array_keys($matrix) as $quarter)
                                    <th class="p-3 text-center">
                                        Q{{ $quarter }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($categories as $category)
                                <tr class="border-b border-zinc-200 dark:border-zinc-800">

                                    <th class="p-3 text-left font-medium">
                                        {{ $category->code === 'sourcing' ? 'Procurement' : $category->display_name }}

                                        @if ($category->code === 'certification')
                                            <span class="block text-xs font-normal text-zinc-500">
                                                Independent exception
                                            </span>
                                        @endif
                                    </th>

                                    @foreach (array_keys($matrix) as $quarter)
                                        @php
                                            $locked = $this->isLocked($quarter, $category->code);
                                        @endphp

                                        <td class="p-3 text-center">

                                            <input type="checkbox" @checked($matrix[$quarter][$category->code] ?? false)
                                                @disabled($locked)
                                                wire:change="toggleCategory({{ $quarter }}, '{{ $category->code }}', $event.target.checked)"
                                                class="rounded border-zinc-300 text-emerald-600"
                                                title="{{ $locked ? 'Required after activation in an earlier quarter' : 'Toggle category' }}">

                                            @if ($locked)
                                                <span class="block text-xs text-zinc-500">
                                                    Locked on
                                                </span>
                                            @endif

                                        </td>
                                    @endforeach

                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

            </div>

            <div class="flex justify-between">

                <flux:button type="button" wire:click="$set('step', 2)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="submit" icon="arrow-right" variant="primary">
                    Save & Continue
                </flux:button>

            </div>

        </form>
    @elseif($step === 4)
        <div
            class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 space-y-6">

            <div>

                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">

                    Industry Settings

                </h2>

                <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">

                    CF Manufacturing has no additional Admin-configurable industry

                    settings in this preparation phase. Protected prices, demand,

                    accounting, tax, financing, and engine parameters remain

                    scenario-controlled.

                </p>

            </div>

            <div class="flex justify-between">

                <flux:button type="button" wire:click="goToStep(3)" icon="arrow-left">

                    Back

                </flux:button>

                <flux:button type="button" wire:click="saveIndustry" icon="check" variant="primary">

                    Acknowledge and Continue

                </flux:button>

            </div>

        </div>
    @elseif($step === 5)
        <flux:card class="space-y-6">

            <div>
                <flux:heading size="lg">
                    Operational Faculty
                </flux:heading>

                <flux:text class="mt-2">
                    This Faculty member will manage the course after deployment,
                    including teams, quarters, accounting elections, decisions,
                    CF Plus, and other Faculty operations.
                </flux:text>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    Assigned Faculty
                </div>

                @if ($course->faculty)
                    <div class="mt-1 text-base font-medium text-zinc-900 dark:text-white">
                        {{ $course->faculty->user->name }}
                    </div>

                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $course->faculty->user->email }}
                    </div>

                    <div class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $course->institution?->name }}
                    </div>
                @else
                    <flux:callout variant="danger" icon="exclamation-triangle"
                        heading="No operational Faculty assigned" />
                @endif
            </div>

            <div class="flex justify-between">
                <flux:button type="button" wire:click="goToStep(4)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="button" wire:click="goToStep(6)" icon="arrow-right" variant="primary">
                    Review and Deploy
                </flux:button>
            </div>

        </flux:card>
    @elseif($step === 6)
        <div class="space-y-6">

            <flux:card class="space-y-6">

                <div>
                    <div class="flex items-center justify-between gap-4">
                        <flux:heading size="lg">
                            Course Configuration
                        </flux:heading>

                        @if ($course->status === 'deployed')
                            <flux:badge color="emerald">
                                Deployed
                            </flux:badge>
                        @else
                            <flux:badge color="amber">
                                Ready for deployment
                            </flux:badge>
                        @endif
                    </div>

                    <dl class="mt-4 grid gap-4 sm:grid-cols-2">

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Course
                            </dt>
                            <dd class="font-medium">
                                {{ $course->name }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Scenario
                            </dt>
                            <dd class="font-medium">
                                {{ $course->scenario->name }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Operational Faculty
                            </dt>
                            <dd class="font-medium">
                                {{ $course->faculty?->user?->name ?? 'Not assigned' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Schedule
                            </dt>
                            <dd>
                                {{ count($quarters) }} quarters,
                                {{ $starts_on }} to {{ $ends_on }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                CF Plus
                            </dt>
                            <dd>
                                <flux:badge :color="$cf_plus_enabled ? 'emerald' : 'zinc'">
                                    {{ $cf_plus_enabled ? 'Enabled' : 'Disabled' }}
                                </flux:badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Accounting Elections
                            </dt>
                            <dd>
                                <flux:badge :color="$accounting_election_enabled ? 'emerald' : 'zinc'">
                                    {{ $accounting_election_enabled ? 'Enabled' : 'Disabled' }}
                                </flux:badge>
                            </dd>
                        </div>

                    </dl>
                </div>

            </flux:card>

            @if ($accounting_election_enabled)
                <flux:callout variant="info" icon="calculator" heading="Student Accounting Election Control"
                    text="Students may propose accounting-method changes beginning in Quarter 4. Faculty approval is required for submitted changes. Inventory Method remains fixed at FIFO." />
            @endif

            @if ($course->status === 'deployed')
                <flux:callout variant="success" icon="check-circle" heading="Course deployed"
                    text="This course is now active. Course preparation is complete and the assigned Faculty can manage the running simulation." />

                <div class="flex justify-start">
                    <flux:button :href="route('admin.courses')" icon="arrow-left" wire:navigate>
                        Back to Simulation Courses
                    </flux:button>
                </div>
            @else
                <flux:callout variant="warning" icon="information-circle" heading="Deployment validation"
                    text="Deployment will revalidate the complete schedule, decision matrix, and Faculty ownership before activating the course." />

                <div class="flex justify-between">

                    <flux:button type="button" wire:click="goToStep(5)" icon="arrow-left">
                        Back
                    </flux:button>

                    <flux:button type="button" wire:click="deploy" icon="rocket-launch" variant="primary">
                        Deploy Course
                    </flux:button>

                </div>
            @endif

        </div>
    @else
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Step {{ $step }}
            </h2>

            <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                This preparation step will be implemented next.
            </p>
        </div>

    @endif
</div>
