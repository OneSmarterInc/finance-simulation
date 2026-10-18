<div class="flexee-page">
    <x-page-header :eyebrow="$course?->name ?? 'New course'" :title="($cf_plus_enabled ? \App\Support\Product::PLUS_NAME : \App\Support\Product::NAME).' · '.\App\Support\Product::INDUSTRY"
        description="{{ $deployed ? 'Review the deployed course configuration.' : 'Configure the signed six-step course setup and deployment workflow.' }}">
        <x-slot:actions>
            @if ($deployed)
                <flux:badge color="emerald" size="lg">
                    Deployed · Review only
                </flux:badge>
            @else
                <flux:badge color="emerald" size="lg">
                    Step {{ $step }} of 6
                </flux:badge>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if (!$deployed)
        <nav aria-label="Course wizard progress" class="mb-8 grid grid-cols-2 gap-2 sm:grid-cols-6">
            @foreach (['Setup', 'Schedule', 'Decisions', 'Methods', 'Faculty', 'Deploy'] as $index => $label)
                @php
                    $wizardStep = $index + 1;
                    $stepDisabled = $wizardStep > 1 && !$course;
                @endphp

                <button type="button" wire:click="goToStep({{ $wizardStep }})" @disabled($stepDisabled)
                    class="min-h-12 rounded-md border px-2 py-2 text-left text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600 disabled:cursor-not-allowed disabled:opacity-50
                    {{ $step === $wizardStep
                        ? 'border-emerald-600 bg-emerald-50 font-semibold text-emerald-800 dark:bg-emerald-400/10 dark:text-emerald-200'
                        : ($step > $wizardStep
                            ? 'border-zinc-300 text-zinc-700 dark:border-zinc-600 dark:text-zinc-300'
                            : 'border-zinc-200 text-zinc-500 dark:border-zinc-700') }}">
                    <span class="block text-xs">{{ $wizardStep }}</span>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    @endif

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" heading="This step needs attention." role="alert">
            <ul class="list-disc pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    @if (!$deployed)
        <div wire:loading.flex wire:target="saveSetup,saveSchedule,saveEnablements,saveIndustry,saveFacultyRoles,deploy"
            role="status" class="items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
            <flux:icon.loading class="size-4" />
            Saving course configuration...
        </div>
    @endif

    {{-- ============================================================
         STEP 1 — SETUP
         ============================================================ --}}
    @if ($step === 1)

        <form wire:submit="saveSetup" class="space-y-6">
            <flux:card class="space-y-6">

                <div class="grid gap-5 md:grid-cols-2">
                    <flux:input class="md:col-span-2" wire:model="name" label="Course name" />

                    <flux:select wire:model="institution_id" label="Institution">
                        @foreach ($institutions as $institution)
                            <flux:select.option :value="$institution->id">
                                {{ $institution->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="scenario_id" label="Scenario">
                        @foreach ($scenarios as $scenario)
                            <flux:select.option :value="$scenario->id">
                                {{ $scenario->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:input wire:model="starts_on" type="date" label="Course starts" />

                    <flux:input wire:model="ends_on" type="date" label="Course ends" />

                </div>

                {{-- Evaluation stage (makes the course Flexee Financial Accounting+) --}}
                <div class="border-y border-zinc-200 py-5 dark:border-zinc-700">
                    <flux:checkbox wire:model.live="cf_plus_enabled"
                        label="Add the evaluation stage (Flexee Financial Accounting+)" />

                    <flux:text size="sm" class="mt-2">
                        Adds quarters at the end of the course where teams study each other's published statements,
                        receive a consultant's valuation and place sealed offers for rival firms. The reveal then shows
                        what each firm was really worth. Leave this off for Flexee Financial Accounting.
                    </flux:text>

                    @if ($cf_plus_enabled)
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            @foreach ([
        'build_quarters' => 'Operating quarters first (from Quarter 4)',
        'adversarial_quarters' => 'Evaluation quarters',
        'post_bid_quarters' => 'Quarters after the evaluation',
    ] as $field => $label)
                                <flux:input wire:model="{{ $field }}" type="number" min="1"
                                    :label="$label" />
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Accounting Election Control --}}
                <div class="border-b border-zinc-200 py-5 dark:border-zinc-700">
                    <div class="flex items-start gap-3">
                        <flux:checkbox wire:model.live="accounting_election_enabled"
                            label="Enable student accounting-election control" />
                    </div>

                    <flux:text size="sm" class="mt-2">
                        When on, each team can keep the default accounting methods or ask to change
                        them, with a written reason, once per quarter. You approve or decline each request.
                        When off, every firm reports with the defaults below.
                    </flux:text>

                    @if ($accounting_election_enabled)
                        <flux:callout class="mt-4" variant="info" icon="information-circle"
                            heading="Student accounting-election workflow enabled"
                            text="Teams can keep the default accounting methods or ask to change them. Each request comes to you with the team's reason, and nothing changes until you approve it." />
                    @else
                        <flux:callout class="mt-4" variant="warning" icon="information-circle"
                            heading="Default accounting workflow"
                            text="Students will continue with the simulation's configured accounting settings. No accounting-method change request or faculty approval will be required." />
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" icon="arrow-right" variant="primary">
                        Save and continue
                    </flux:button>
                </div>

            </flux:card>
        </form>

        {{-- ============================================================
         STEP 2 — SCHEDULE
         ============================================================ --}}
    @elseif($step === 2)
        <form wire:submit="saveSchedule" class="space-y-6">

            <div class="flex flex-wrap items-end gap-3">
                <flux:input class="w-40" wire:model="quarter_count" type="number" min="4"
                    label="Configured quarters" />

                <flux:button type="button" wire:click="buildScheduleRows" icon="table-cells">
                    Generate rows
                </flux:button>
            </div>

            @if ($cf_plus_enabled)
                <flux:callout variant="warning" icon="information-circle" heading="Schedule for the evaluation stage"
                    text="This course needs 3 warm-up + {{ $build_quarters }} operating + {{ $adversarial_quarters }} evaluation + {{ $post_bid_quarters }} later quarters." />
            @endif

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="p-2">Quarter</th>
                            <th class="p-2">Start</th>
                            <th class="p-2">End</th>
                            <th class="p-2">Decision deadline (end of day)</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($quarters as $index => $quarter)
                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                <td class="p-2 font-medium">
                                    Q{{ $quarter['quarter_number'] }}

                                    @if ($quarter['quarter_number'] <= 3)
                                        <span class="block text-xs font-normal text-zinc-500">
                                            Warm-up / reserved
                                        </span>
                                    @elseif($quarter['quarter_number'] === 4)
                                        <span class="block text-xs font-normal text-emerald-700 dark:text-emerald-400">
                                            Student control begins
                                        </span>
                                    @endif
                                </td>

                                <td class="p-2">
                                    <input wire:model="quarters.{{ $index }}.starts_on" type="date"
                                        class="rounded-md border-zinc-300 dark:border-zinc-700">
                                </td>

                                <td class="p-2">
                                    <input wire:model="quarters.{{ $index }}.ends_on" type="date"
                                        class="rounded-md border-zinc-300 dark:border-zinc-700">
                                </td>

                                <td class="p-2">
                                    <input wire:model="quarters.{{ $index }}.decision_deadline_at"
                                        type="date" class="rounded-md border-zinc-300 dark:border-zinc-700">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-between">
                <flux:button type="button" wire:click="goToStep(1)" icon="arrow-left">
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
        <form wire:submit="saveEnablements" class="space-y-5">

            @php
                $requiredQ4 = \App\Services\Courses\CourseConfigurationService::requiredQuarterFour($course ?? null);
            @endphp
            <flux:callout variant="success" icon="information-circle" heading="Student control begins at Quarter 4"
                :text="in_array('production', $requiredQ4, true)
                    ? 'Quarters 1 to 3 are the warm-up, run by the simulation. Quarter 4 must open forecasting, sourcing and production, and an area stays open once you open it. Open the other areas gradually so each quarter gives students something new to explain.'
                    : 'Quarters 1 to 3 are the warm-up, run by the simulation. Quarter 4 must open forecasting and sourcing. Until you open production, the plant makes what each team forecasts, less the units it already has in stock. An area stays open once you open it. Open the other areas gradually so each quarter gives students something new to explain.'" />

            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-sm">
                    <thead>
                        <tr class="border-b">
                            <th class="p-3 text-left">Category</th>

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
                                    {{ \App\Support\Presentation\DecisionPresenter::categoryLabel($category->code) }}

                                    @if ($category->code === 'certification')
                                        <span class="block text-xs font-normal text-zinc-500">
                                            Nothing for students to enter yet; recorded automatically
                                        </span>
                                    @elseif ($category->code === 'notes_elections')
                                        <span class="block text-xs font-normal text-zinc-500">
                                            On the decision form only when student accounting-election control is off
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

            <div class="flex justify-between">
                <flux:button type="button" wire:click="goToStep(2)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="submit" icon="arrow-right" variant="primary">
                    Save and continue
                </flux:button>
            </div>

        </form>

        {{-- ============================================================
         STEP 4 — INDUSTRY
         ============================================================ --}}
    @elseif($step === 4)
        <form wire:submit="saveIndustry" class="space-y-6">
        <flux:card class="space-y-6">

            <div>
                <flux:heading size="lg">Starting accounting methods</flux:heading>
                <flux:text class="mt-2">
                    Every firm reports with these methods in its three warm-up quarters and keeps them until a change is approved.
                    Two sections of the same course can start from different methods, which makes a good class comparison.
                    Inventory is always first-in, first-out.
                </flux:text>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                @foreach (app(\App\Services\CfPlus\FirmAccountingElectionService::class)->studentVisibleFields() as $field)
                    @php
                        $key = $field['key'];
                    @endphp
                    <div class="space-y-1" wire:key="starting-{{ $key }}">
                        <flux:select wire:model.live="starting_elections.{{ $key }}" :label="\App\Support\Presentation\DecisionPresenter::fieldLabel($key)">
                            @foreach ($field['allowed_values'] as $option)
                                <flux:select.option :value="$option">{{ \App\Support\Presentation\DecisionPresenter::valueLabel($key, $option) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:text size="sm">{{ \App\Support\Presentation\AccountingMethodGuide::effect($key, $starting_elections[$key] ?? '') }}</flux:text>
                    </div>
                @endforeach
            </div>
            @error('starting_elections')
                <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" />
            @enderror

            <flux:text>
                Manufacturing has no further settings for you to choose. Prices, demand, tax,
                financing and the other industry parameters are set by the simulation.
            </flux:text>

            <div class="flex justify-between">
                <flux:button type="button" wire:click="goToStep(3)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="submit" icon="check" variant="primary">
                    Save and continue
                </flux:button>
            </div>

        </flux:card>
        </form>

        {{-- ============================================================
         STEP 5 — FACULTY
         ============================================================ --}}
    @elseif($step === 5)
        <form wire:submit="saveFacultyRoles">

            <flux:card class="space-y-6">

                <div>
                    <flux:heading size="lg">
                        Faculty roles
                    </flux:heading>

                    <flux:text class="mt-2">
                        You remain the course owner. Optionally assign co-faculty from your institution.
                    </flux:text>
                </div>

                <div class="space-y-3">
                    @forelse($facultyChoices as $faculty)
                        <flux:checkbox wire:model="co_faculty_ids" value="{{ $faculty->id }}"
                            :label="$faculty->user->name.' · '.$faculty->user->email" />
                    @empty
                        <flux:callout icon="user-group"
                            heading="No other faculty are available in this institution" />
                    @endforelse
                </div>

                <div class="flex justify-between">
                    <flux:button type="button" wire:click="goToStep(4)" icon="arrow-left">
                        Back
                    </flux:button>

                    <flux:button type="submit" icon="arrow-right" variant="primary">
                        Save and review
                    </flux:button>
                </div>

            </flux:card>

        </form>

        {{-- ============================================================
         STEP 6 — DEPLOY / REVIEW
         ============================================================ --}}
    @else
        <div class="space-y-6">

            @php
                $reviewQuarters = $course->quarters()->with('enablements.category')->orderBy('quarter_number')->get();
                $reviewStatus = \App\Services\Courses\CourseStatus::for($course);
                $reviewCategories = $course->scenario->decisionCategories()->orderBy('sort_order')->get();
                $defaults = $course->startingElections();
            @endphp
            <flux:card class="space-y-6">
                <div class="flex items-center justify-between gap-4">
                    <flux:heading size="lg">Course configuration</flux:heading>
                    @if ($deployed || $course->status === 'deployed')
                        <flux:badge color="emerald">Running</flux:badge>
                    @endif
                </div>

                <dl class="grid gap-4 sm:grid-cols-2">
                    <div><dt class="text-xs text-zinc-500">Course</dt><dd class="font-medium">{{ $course->name }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">Product</dt><dd class="font-medium">{{ \App\Support\Product::fullName($course) }}</dd></div>
                    <div><dt class="text-xs text-zinc-500">Dates</dt>
                        <dd>{{ \App\Support\Dates::day($course->starts_on) }} to {{ \App\Support\Dates::day($course->ends_on) }}, {{ $reviewQuarters->count() }} quarters</dd></div>
                    <div><dt class="text-xs text-zinc-500">Evaluation stage</dt>
                        <dd>{{ $cf_plus_enabled ? 'Added: opens with Quarter '.$reviewStatus->evaluationStart() : 'Not added' }}</dd></div>
                </dl>

                {{-- Item 23: the schedule and the decision areas, read-only once running --}}
                <div class="space-y-2">
                    <flux:heading size="md">Schedule</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-left text-zinc-500 dark:border-white/10">
                                    <th class="py-2 pr-4 font-medium">Quarter</th><th class="py-2 pr-4 font-medium">Starts</th>
                                    <th class="py-2 pr-4 font-medium">Decisions due</th><th class="py-2 pr-4 font-medium">State</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                                @foreach ($reviewQuarters as $q)
                                    <tr>
                                        <td class="py-2 pr-4 font-medium">Quarter {{ $q->quarter_number }}
                                            @if ($reviewStatus->isEvaluationQuarter($q)) <span class="text-xs text-violet-700 dark:text-violet-300">· evaluation stage</span> @endif</td>
                                        <td class="py-2 pr-4">{{ \App\Support\Dates::day($q->starts_on) }}</td>
                                        <td class="py-2 pr-4">{{ $q->quarter_number <= 3 ? 'Warm-up' : \App\Support\Dates::dateTime($q->decision_deadline_at) }}</td>
                                        <td class="py-2 pr-4">{{ $reviewStatus->stateLabel($q) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="space-y-2">
                    <flux:heading size="md">Decision areas open to students</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                                    <th class="py-2 pr-4 text-left font-medium">Area</th>
                                    @foreach ($reviewQuarters->where('quarter_number', '>=', 4) as $q)
                                        <th class="px-2 py-2 text-center font-medium">Q{{ $q->quarter_number }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                                @foreach ($reviewCategories as $category)
                                    <tr>
                                        <th class="py-2 pr-4 text-left font-normal">{{ \App\Support\Presentation\DecisionPresenter::categoryLabel($category->code) }}</th>
                                        @foreach ($reviewQuarters->where('quarter_number', '>=', 4) as $q)
                                            @php($on = (bool) $q->enablements->firstWhere('scenario_decision_category_id', $category->id)?->enabled)
                                            <td class="px-2 py-2 text-center {{ $on ? 'text-emerald-700 dark:text-emerald-300' : 'text-zinc-400' }}">{{ $on ? 'Open' : '–' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <flux:text size="sm">Areas not open to students are chosen by the simulation each quarter.</flux:text>
                </div>
            </flux:card>

            {{-- ====================================================
                 STUDENT REGISTRATION
                 ==================================================== --}}
            <div
                class="rounded-lg border p-5
                    {{ $course->registration_enabled
                        ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/30'
                        : 'border-zinc-300 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/40' }}">

                <div class="flex items-start justify-between gap-4">

                    <div class="flex items-start gap-3">

                        <div
                            class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full
                                {{ $course->registration_enabled
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'
                                    : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' }}">
                            <flux:icon name="user-plus" class="size-5" />
                        </div>

                        <div>
                            <flux:heading size="md">
                                Student registration
                            </flux:heading>

                            <flux:text class="mt-1">
                                {{ $course->registration_enabled ? 'Registration is open for this course' : 'Registration is currently closed' }}
                            </flux:text>
                        </div>

                    </div>

                    <div class="flex items-center gap-2"> 
                        <flux:badge :color="$course->registration_enabled ? 'emerald' : 'zinc'"> 
                            {{ $course->registration_enabled ? 'Open' : 'Closed' }} 
                        </flux:badge>

                        <flux:button type="button" size="sm"
                            variant="{{ $course->registration_enabled ? 'danger' : 'primary' }}"
                            wire:click="toggleRegistration"
                            wire:confirm="{{ $course->registration_enabled
                                ? 'Close student registration for this course? Existing enrollments will not be affected.'
                                : 'Open student registration for this course?' }}">

                            {{ $course->registration_enabled ? 'Close Registration' : 'Open Registration' }} 
                        </flux:button>

                    </div>

                </div>

                @if ($course->registration_token)
                    <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">

                        <flux:text size="sm" class="mb-2">
                            <strong>Student registration link</strong>
                        </flux:text>

                        <div class="flex gap-2">

                            <input type="text" readonly
                                value="{{ url('/register/course/' . $course->registration_token) }}"
                                class="min-w-0 flex-1 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" />

                            <flux:button type="button" variant="primary" icon="clipboard"
                                x-on:click="navigator.clipboard.writeText('{{ url('/register/course/' . $course->registration_token) }}')">
                                Copy Link
                            </flux:button>

                        </div>

                        <flux:text size="sm" class="mt-2">
                            Share this link with students to allow them to register for this course.
                        </flux:text>

                    </div>
                @else
                    <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:text size="sm">
                            A student registration link is not available for this course.
                        </flux:text>
                    </div>
                @endif

            </div>

            {{-- ====================================================
                 ACCOUNTING ELECTION CONTROL — HIGHLIGHTED
                 ==================================================== --}}
            <div
                class="rounded-lg border p-5
                    {{ $accounting_election_enabled
                        ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950/30'
                        : 'border-zinc-300 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/40' }}">
                <div class="flex items-start justify-between gap-4">

                    <div class="flex items-start gap-3">

                        <div
                            class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full
                                {{ $accounting_election_enabled
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'
                                    : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' }}">
                            <flux:icon name="calculator" class="size-5" />
                        </div>

                        <div>
                            <flux:heading size="md">
                                Student accounting-election control
                            </flux:heading>

                            <flux:text class="mt-1">
                                {{ $accounting_election_enabled
                                    ? 'Enabled for this simulation'
                                    : 'Off: every firm uses the default methods' }}
                            </flux:text>
                        </div>

                    </div>

                    <flux:badge :color="$accounting_election_enabled ? 'emerald' : 'zinc'">
                        {{ $accounting_election_enabled ? 'Enabled' : 'Disabled' }}
                    </flux:badge>

                </div>

                <div class="mt-4 border-t border-zinc-200 pt-4 text-sm dark:border-zinc-700">
                    <p>
                        @if ($accounting_election_enabled)
                            Each team starts with the methods below and can ask to change them, with a written reason, once per quarter. A request is the team's, filed by any member; you approve or decline it, and an approved change is disclosed in that quarter's notes.
                        @else
                            Every firm reports with the methods below throughout the course.
                        @endif
                    </p>
                    <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                        @foreach ($defaults as $key => $value)
                            <div class="flex justify-between gap-3 rounded-md bg-white/60 px-3 py-2 dark:bg-white/5">
                                <dt class="text-zinc-600 dark:text-zinc-400">{{ \App\Support\Presentation\DecisionPresenter::fieldLabel($key) }}</dt>
                                <dd class="font-medium">{{ \App\Support\Presentation\DecisionPresenter::valueLabel($key, $value) }}{{ $key === 'inventory_method' ? ' (fixed)' : '' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>

            </div>

            {{-- ====================================================
                 DEPLOYMENT STATUS
                 ==================================================== --}}
            @if ($deployed || $course->status === 'deployed')
                <flux:callout variant="success" icon="check-circle" heading="Course deployed"
                    text="This course is running and its setup is locked. Use the course pages in the left menu to manage firms, students, quarters and results." />

            @else
                <flux:callout variant="warning" icon="information-circle" heading="Deployment validation"
                    text="Deployment revalidates the complete schedule, matrix, and faculty ownership atomically." />

                <div class="flex justify-between">

                    <flux:button type="button" wire:click="goToStep(5)" icon="arrow-left">
                        Back
                    </flux:button>

                    <flux:button type="button" wire:click="deploy" icon="rocket-launch" variant="primary">
                        Deploy course
                    </flux:button>

                </div>
            @endif

        </div>

    @endif
</div>
