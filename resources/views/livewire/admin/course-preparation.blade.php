<div class="space-y-6">
    <x-page-header eyebrow="Administration" title="Course preparation" :description="$course ? 'Set up '.$course->name.' for its faculty member.' : 'Set up a new course for a faculty member.'">
        <x-slot:actions>
            <flux:button :href="route('admin.courses')" variant="ghost" icon="arrow-left" wire:navigate>
                Courses
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
                    Course setup
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    The course's details and the faculty member who will run it.
                </p>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <flux:input wire:model="name" label="Course name" />

                <flux:select wire:model="institution_id" label="Institution">
                    <option value="">Select institution</option>

                    @foreach ($institutions as $institution)
                        <option value="{{ $institution->id }}">
                            {{ $institution->name }}
                        </option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="faculty_id" label="Faculty">
                    <option value="">Select faculty</option>

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

                <flux:input wire:model="starts_on" type="date" label="Course starts" />

                <flux:input wire:model="ends_on" type="date" label="Course ends" />

            </div>

            <div class="mt-8 space-y-6 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <div>
                    <h3 class="font-medium text-zinc-900 dark:text-white">
                        Evaluation stage
                    </h3>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Adds quarters at the end where teams study each other's firms and bid for them, making the course Flexee Financial Accounting+.
                    </p>
                </div>

                <flux:checkbox wire:model="cf_plus_enabled" label="Add the evaluation stage (Flexee Financial Accounting+)" />

                @if ($cf_plus_enabled)
                    <div class="grid gap-6 md:grid-cols-3">
                        <flux:input wire:model="build_quarters" type="number" min="0" label="Operating quarters first (from Quarter 4)" />

                        <flux:input wire:model="adversarial_quarters" type="number" min="0"
                            label="Evaluation quarters" />

                        <flux:input wire:model="post_bid_quarters" type="number" min="0"
                            label="Quarters after the evaluation" />
                    </div>
                @endif
            </div>

            <div class="mt-8 border-t border-zinc-200 pt-6 dark:border-zinc-700">
                <flux:checkbox wire:model="accounting_election_enabled" label="Let teams ask to change their accounting methods" />

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Each team can ask once a quarter, with a reason, and faculty approve or decline.
                </p>
            </div>
        </div>

        <div class="flex justify-end">
            <flux:button wire:click="saveSetup" variant="primary" icon="arrow-right">
                Save and continue
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
                        Course schedule
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Quarters 1 to 3 are the warm-up. The simulation plays them the moment the course is deployed, so they take none of the term.
                        Quarter 4 starts on the course start date.
                    </p>
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <flux:input class="w-40" wire:model="quarter_count" type="number" min="4"
                        label="Quarters, warm-up included" />

                    <flux:button type="button" wire:click="buildScheduleRows" icon="table-cells">
                        Generate rows
                    </flux:button>
                </div>

                @if ($cf_plus_enabled)
                    <flux:callout class="mt-6" variant="warning" icon="information-circle"
                        heading="Schedule for the evaluation stage"
                        text="This course needs {{ $this->minimumQuarters() }} quarters: 3 warm-up + {{ $build_quarters }} operating + {{ $adversarial_quarters }} evaluation + {{ $post_bid_quarters }} later." />
                @endif

                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700 text-left">
                                <th class="p-3">Quarter</th>
                                <th class="p-3">Start</th>
                                <th class="p-3">End</th>
                                <th class="p-3">Decision deadline (end of day)</th>
                            </tr>
                        </thead>

                        <tbody>
                            @if (collect($quarters)->contains(fn ($q) => $q['quarter_number'] <= 3))
                                <tr class="border-b border-zinc-200 dark:border-zinc-800" data-test="warmup-row">
                                    <td class="p-3 font-medium">Q1 to Q3
                                        <span class="block text-xs font-normal text-zinc-500">Warm-up</span></td>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-300" colspan="3">Played when the course is deployed, before it starts. No dates to set.</td>
                                </tr>
                            @endif
                            @forelse($quarters as $index => $quarter)
                                @continue($quarter['quarter_number'] <= 3)
                                <tr class="border-b border-zinc-200 dark:border-zinc-800">

                                    <td class="p-3 font-medium">
                                        Q{{ $quarter['quarter_number'] }}

                                        @if ($quarter['quarter_number'] === 4)
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
                    Save and continue
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
                    Save and continue
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

                    Manufacturing has no further settings to choose here. Protected prices, demand,

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
                    Faculty
                </flux:heading>

                <flux:text class="mt-2">
                    This faculty member runs the course once it's deployed: its teams, quarters,
                    accounting-method requests and evaluation stage.
                </flux:text>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                    Faculty member
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
                        heading="No faculty member assigned" />
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
                            How the course is set up
                        </flux:heading>

                        @if ($course->status === 'deployed')
                            <flux:badge color="emerald">
                                Deployed
                            </flux:badge>
                        @else
                            <flux:badge color="amber">
                                Ready to deploy
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
                                Faculty
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
                                {{ \App\Support\Dates::day(\Carbon\CarbonImmutable::parse($starts_on)) }} to {{ \App\Support\Dates::day(\Carbon\CarbonImmutable::parse($ends_on)) }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Evaluation stage
                            </dt>
                            <dd>
                                <flux:badge :color="$cf_plus_enabled ? 'emerald' : 'zinc'">
                                    {{ $cf_plus_enabled ? 'Added' : 'Not added' }}
                                </flux:badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-xs text-zinc-500">
                                Accounting-method requests
                            </dt>
                            <dd>
                                <flux:badge :color="$accounting_election_enabled ? 'emerald' : 'zinc'">
                                    {{ $accounting_election_enabled ? 'On' : 'Off' }}
                                </flux:badge>
                            </dd>
                        </div>

                    </dl>
                </div>

            </flux:card>

            @if ($accounting_election_enabled)
                <flux:callout variant="info" icon="calculator" heading="Teams can ask to change their methods"
                    text="Students may propose accounting-method changes beginning in Quarter 4. Faculty approval is required for submitted changes. Inventory stays first-in, first-out." />
            @endif

            @if ($course->status === 'deployed')
                <flux:callout variant="success" icon="check-circle" heading="Course deployed"
                    text="The course is running. Its faculty member manages it from their own pages." />

                <div class="flex justify-start">
                    <flux:button :href="route('admin.courses')" icon="arrow-left" wire:navigate>
                        Back to courses
                    </flux:button>
                </div>
            @else
                <flux:callout variant="warning" icon="information-circle" heading="Deployment validation"
                    text="Deploying checks the schedule, the decision areas and the faculty member once more before the course opens." />

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
