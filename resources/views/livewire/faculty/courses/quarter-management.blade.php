@php use App\Support\Dates; use App\Models\CourseQuarter; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Quarters"
        description="Each quarter stays open until its deadline. Then the system locks every firm's decisions, calculates results and opens the next quarter. A firm that hasn't submitted keeps its previous quarter's decisions." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" heading="This action needs attention" role="alert">
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </flux:callout>
    @endif

    @if ($course->cf_plus_enabled && ($evaluation = $status->evaluationQuarter()) && $evaluation->state === CourseQuarter::LOCKED)
        <flux:callout variant="warning" icon="scale" heading="The evaluation stage opens with Quarter {{ $evaluation->quarter_number }}">
            <flux:callout.text>
                {{ $status->evaluationOpensText() }} At that moment every firm's membership freezes and every firm's published statements become visible to its rivals. This can't be undone, so make sure firms and teams are final before then.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-3" aria-label="Course quarters">
        @if ($quarters->where('quarter_number', '<=', 3)->isNotEmpty())
            <flux:card size="sm">
                <div class="flex flex-wrap items-center gap-3">
                    <flux:heading size="lg">Quarters 1 to 3</flux:heading>
                    <flux:badge color="sky" size="sm">Warm-up</flux:badge>
                    <flux:text size="sm">Run by the simulation before teams take over, so every firm starts with three quarters of statements to read.</flux:text>
                </div>
            </flux:card>
        @endif

        @foreach ($quarters->where('quarter_number', '>=', 4) as $quarter)
            @php
                $label = $status->stateLabel($quarter);
                $failedAttempts = $quarter->closeAttempts->where('status', 'failed');
                $successfulAttempts = $quarter->closeAttempts->where('status', 'succeeded');
                $diagnosticAttempts = $quarter->closeAttempts
                    ->where('status', 'succeeded')
                    ->filter(fn ($attempt) => ! empty($attempt->diagnostic_detail['operating_outcome_violations'] ?? []));
            @endphp

            <flux:card size="sm" wire:key="quarter-{{ $quarter->id }}">
                <div class="grid gap-4 md:grid-cols-[10rem_minmax(0,1fr)_auto] md:items-start">
                    <div class="flex items-center justify-between gap-3 md:block">
                        <flux:heading size="lg">Quarter {{ $quarter->quarter_number }}</flux:heading>
                        <div class="flex flex-wrap gap-1 md:mt-2">
                            <flux:badge :color="$status->stateColor($quarter)" size="sm">{{ $label }}</flux:badge>
                            @if ($status->isEvaluationQuarter($quarter))
                                <flux:badge color="violet" size="sm">Evaluation stage</flux:badge>
                            @endif
                        </div>
                    </div>

                    <div class="min-w-0 space-y-2 text-sm">
                        @if ($quarter->quarter_number <= 3)
                            <flux:text size="sm">Warm-up quarter, run by the simulation before teams take over.</flux:text>
                        @elseif ($quarter->state === CourseQuarter::OPEN)
                            @if (\App\Services\Quarters\QuarterScheduler::needsNewDeadline($quarter))
                                <p class="text-red-700 dark:text-red-300">This quarter opened after its deadline, so it won't close on its own. Set a new deadline.</p>
                            @elseif ($status->isOverdue($quarter))
                                <p class="text-red-700 dark:text-red-300" data-test="deadline-passed">The deadline passed {{ \App\Services\Courses\CourseStatus::ago($quarter->decision_deadline_at) }} and the quarter hasn't closed. Quarters close within a minute of their deadline, so the quarter clock on the server may not be running. Extend the deadline, or ask support to check.</p>
                            @endif
                            <p>
                                Decisions due <strong>{{ Dates::dateTime($quarter->decision_deadline_at) }}</strong>
                                ({{ Dates::remaining($quarter->decision_deadline_at) }}).
                                @if ($quarter->deadline_extended_at)
                                    <span class="text-zinc-500">Extended {{ Dates::day($quarter->deadline_extended_at) }}.</span>
                                @endif
                            </p>
                            @php($missing = $status->notSubmitted($quarter))
                            <p>
                                {{ $status->submittedCount($quarter) }} of {{ $status->firms->count() }} firms have submitted.
                                @if ($missing->isNotEmpty())
                                    <span class="text-amber-700 dark:text-amber-300">Not yet: {{ $missing->pluck('name')->join(', ') }}.</span>
                                @endif
                            </p>
                        @elseif ($quarter->state === CourseQuarter::PROCESSING)
                            <p>Results are being calculated. {{ $successfulAttempts->count() }} of {{ $quarter->closeAttempts->count() }} firms complete.</p>
                            @foreach ($failedAttempts as $attempt)
                                <p class="text-red-700 dark:text-red-300">
                                    {{ $attempt->firm->name }} ({{ $attempt->firm->code }}) ·
                                    {{ ucfirst($attempt->safe_error['category'] ?? 'system') }}:
                                    {{ $attempt->safe_error['message'] ?? 'Close failed.' }}
                                </p>
                            @endforeach
                            @if ($failedAttempts->isNotEmpty())
                                <flux:text size="sm">The next quarter waits until every firm's results are ready.</flux:text>
                            @endif
                        @elseif ($quarter->state === CourseQuarter::CLOSED)
                            <p>
                                Closed {{ Dates::dateTime($quarter->closed_at) }}.
                                @php($carried = $quarter->decisions()->where('carried_forward', true)->with('firm')->get())
                                @if ($carried->isNotEmpty())
                                    <span class="text-zinc-500">No submission, previous decisions used: {{ $carried->pluck('firm.name')->join(', ') }}.</span>
                                @endif
                            </p>
                        @else
                            <p>{{ $status->opensWhen($quarter) }} @unless ($quarter->decision_deadline_at?->isPast()) Decisions due {{ Dates::dateTime($quarter->decision_deadline_at) }}. @endunless</p>
                        @endif

                        @foreach ($diagnosticAttempts as $attempt)
                            @foreach ($attempt->diagnostic_detail['operating_outcome_violations'] ?? [] as $violation)
                                <p class="text-amber-700 dark:text-amber-300">
                                    {{ $attempt->firm->name }} ({{ $attempt->firm->code }}) · Plausibility: unusual
                                    {{ str($violation['rule'] ?? 'operating outcome')->replace('_', ' ') }}
                                    · calculated {{ is_numeric($violation['calculated'] ?? null) ? number_format((float) $violation['calculated'], 2) : 'n/a' }},
                                    expected {{ $violation['allowed_range'][0] ?? 'n/a' }} to {{ $violation['allowed_range'][1] ?? 'n/a' }}
                                    <span class="text-xs text-zinc-500">({{ $violation['rule'] ?? '' }})</span>
                                </p>
                            @endforeach
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-2 md:justify-end">
                        @if ($quarter->state === CourseQuarter::OPEN)
                            <form wire:submit="extend('{{ $quarter->id }}')" class="flex flex-wrap items-end gap-2">
                                <flux:input type="datetime-local" wire:model="newDeadline" label="New deadline" size="sm" />
                                <flux:button type="submit" size="sm" icon="clock">Extend</flux:button>
                            </form>
                        @endif

                        @if ($quarter->state === CourseQuarter::PROCESSING && $failedAttempts->isNotEmpty())
                            <flux:button wire:click="retry('{{ $quarter->id }}')" wire:loading.attr="disabled"
                                wire:target="retry('{{ $quarter->id }}')" icon="arrow-path" variant="primary">
                                Retry failed firms
                            </flux:button>
                        @endif
                    </div>
                </div>
            </flux:card>
        @endforeach
    </div>

    <div wire:loading role="status" class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
        <flux:icon.loading class="size-4" />
        <span>Working...</span>
    </div>
</div>
