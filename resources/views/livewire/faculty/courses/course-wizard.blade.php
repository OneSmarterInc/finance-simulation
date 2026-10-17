<div class="flexee-page">
    <x-page-header eyebrow="Faculty · Courses" title="CF Manufacturing course"
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
            @foreach (['Setup', 'Schedule', 'Decisions', 'Industry', 'Faculty', 'Deploy'] as $index => $label)
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

                    <div class="md:col-span-2">
                        <flux:input wire:model="payment_mode" type="text" maxlength="100"
                            label="Payment or access mode" placeholder="Optional configuration reference" />

                        <flux:text size="sm" class="mt-1">
                            Configuration only; no billing or payout behavior is performed.
                        </flux:text>
                    </div>
                </div>

                {{-- CF Plus --}}
                <div class="border-y border-zinc-200 py-5 dark:border-zinc-700">
                    <flux:checkbox wire:model.live="cf_plus_enabled"
                        label="Enable CF Plus adversarial and post-bid phases" />

                    @if ($cf_plus_enabled)
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            @foreach ([
        'build_quarters' => 'Build quarters',
        'adversarial_quarters' => 'Adversarial quarters',
        'post_bid_quarters' => 'Post-bid quarters',
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
                        When enabled, students can choose in Quarter 4 to either
                        continue with the simulation's default accounting settings
                        or propose different accounting methods for faculty review.
                        When disabled, the existing default accounting workflow is used.
                    </flux:text>

                    @if ($accounting_election_enabled)
                        <flux:callout class="mt-4" variant="info" icon="information-circle"
                            heading="Student accounting-election workflow enabled"
                            text="Students will be able to use the default accounting settings or propose accounting-method changes in Quarter 4. Faculty approval is required only when a student proposes a change." />
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
                <flux:callout variant="warning" icon="information-circle" heading="CF Plus schedule requirement"
                    text="CF Plus needs 3 warm-up + {{ $build_quarters }} build + {{ $adversarial_quarters }} adversarial + {{ $post_bid_quarters }} post-bid quarters." />
            @endif

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="p-2">Quarter</th>
                            <th class="p-2">Start</th>
                            <th class="p-2">End</th>
                            <th class="p-2">Decision deadline</th>
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

            <flux:callout variant="success" icon="information-circle" heading="Student control begins at Quarter 4"
                text="Q1-Q3 are reserved for system-generated warm-up history. Quarter 4 requires Forecasting, Procurement (sourcing), and Production. Operating categories stay open after activation. Certification is independent." />

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
        <flux:card class="space-y-6">

            <div>
                <flux:heading size="lg">
                    Industry settings
                </flux:heading>

                <flux:text class="mt-2">
                    CF Manufacturing has no additional faculty-configurable industry
                    settings in Phase 6. Protected prices, demand, accounting, tax,
                    financing, and engine parameters remain scenario-controlled.
                </flux:text>
            </div>

            <div class="flex justify-between">
                <flux:button type="button" wire:click="goToStep(3)" icon="arrow-left">
                    Back
                </flux:button>

                <flux:button type="button" wire:click="saveIndustry" icon="check" variant="primary">
                    Acknowledge and continue
                </flux:button>
            </div>

        </flux:card>

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

            <flux:card class="space-y-6">

                <div>
                    <div class="flex items-center justify-between gap-4">
                        <flux:heading size="lg">
                            Course configuration
                        </flux:heading>

                        @if ($deployed || $course->status === 'deployed')
                            <flux:badge color="emerald">
                                Deployed
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

                    </dl>
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
                                Student Registration
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
                                Student Accounting Election Control
                            </flux:heading>

                            <flux:text class="mt-1">
                                {{ $accounting_election_enabled
                                    ? 'Enabled for this simulation'
                                    : 'Disabled — default accounting settings will be used' }}
                            </flux:text>
                        </div>

                    </div>

                    <flux:badge :color="$accounting_election_enabled ? 'emerald' : 'zinc'">
                        {{ $accounting_election_enabled ? 'Enabled' : 'Disabled' }}
                    </flux:badge>

                </div>

                @if ($accounting_election_enabled)
                    <div class="mt-4 border-t border-emerald-200 pt-4 dark:border-emerald-800">

                        <flux:text size="sm">
                            <strong>What this means:</strong>
                            Students will have the option in Quarter 4 to either
                            continue with the simulation's default accounting settings
                            or propose different accounting methods.
                        </flux:text>

                        <ul class="mt-3 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">

                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>
                                    Students can continue with the default accounting
                                    settings without requesting a change.
                                </span>
                            </li>

                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>
                                    Students who choose to change accounting methods
                                    can submit their proposed selections for faculty review.
                                </span>
                            </li>

                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>
                                    Faculty approval is required only when a student
                                    proposes a change.
                                </span>
                            </li>

                            <li class="flex gap-2">
                                <span class="text-emerald-600">✓</span>
                                <span>
                                    Inventory Method remains fixed at FIFO and cannot be changed.
                                </span>
                            </li>

                        </ul>

                        <flux:callout class="mt-4" variant="info" icon="information-circle"
                            heading="Student workflow"
                            text="Quarter 4 → Choose Default or Change Settings → If changed, submit for faculty review → Continue with the applicable accounting settings." />

                    </div>
                @else
                    <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">

                        <flux:text size="sm">
                            <strong>What this means:</strong>
                            Students will use the simulation's existing/default accounting
                            settings. No accounting-method change request or faculty approval
                            workflow will be required.
                        </flux:text>

                    </div>
                @endif

            </div>

            {{-- ====================================================
                 DEPLOYMENT STATUS
                 ==================================================== --}}
            @if ($deployed || $course->status === 'deployed')
                <flux:callout variant="success" icon="check-circle" heading="Course deployed"
                    text="This course is now active. Course configuration is locked. Use Firms, Quarters, and CF Plus from the faculty dashboard to manage the running simulation." />

                <div class="flex justify-start">
                    <flux:button :href="route('dashboard')" icon="arrow-left">
                        Back to dashboard
                    </flux:button>
                </div>
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
