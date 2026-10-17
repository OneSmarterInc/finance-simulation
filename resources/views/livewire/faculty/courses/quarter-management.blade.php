<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Quarter management"
        description="Open upcoming quarters, monitor close progress, and retry firms that need attention.">
        <x-slot:actions>
            <flux:button :href="route('dashboard')" icon="arrow-left">
                Back to dashboard
            </flux:button>
        </x-slot:actions>
    </x-page-header>

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

    <div class="space-y-3" aria-label="Course quarters">
        @foreach ($quarters as $quarter)
            @php
                $isInherited = $quarter->quarter_number <= 3;

                $failedAttempts = $quarter->closeAttempts->where('status', 'failed');

                $successfulAttempts = $quarter->closeAttempts->where('status', 'succeeded');

                $diagnosticAttempts = $quarter->closeAttempts
                    ->where('status', 'succeeded')
                    ->filter(fn($attempt) => !empty($attempt->diagnostic_detail['operating_outcome_violations'] ?? []));

                $totalAttempts = $quarter->closeAttempts->count();

                $activeQuarter = $quarters->first(
                    fn($item) => $item->quarter_number >= 4 && in_array($item->state, ['open', 'processing'], true),
                );

                $hasActiveQuarter = $activeQuarter !== null;

                $previousQuarter = $quarters->firstWhere('quarter_number', $quarter->quarter_number - 1);

                $canOpen =
                    !$isInherited &&
                    $quarter->state === 'locked' &&
                    !$hasActiveQuarter &&
                    ($quarter->quarter_number === 4 || $previousQuarter?->state === 'closed');

                $state = $isInherited ? 'CLOSED / inherited' : strtoupper($quarter->state);

                $badgeColor = match (true) {
                    $failedAttempts->isNotEmpty() => 'red',
                    $quarter->state === 'open' => 'emerald',
                    $quarter->state === 'processing' => 'amber',
                    $quarter->state === 'closed' => 'emerald',
                    default => 'zinc',
                };
            @endphp

            <flux:card size="sm" wire:key="quarter-{{ $quarter->id }}">
                <div class="grid gap-4 md:grid-cols-[8rem_minmax(0,1fr)_auto] md:items-center">

                    {{-- Quarter / state --}}
                    <div class="flex items-center justify-between gap-3 md:block">
                        <flux:heading size="lg">
                            Quarter {{ $quarter->quarter_number }}
                        </flux:heading>

                        <flux:badge :color="$badgeColor" size="sm" class="md:mt-2">
                            {{ $state }}
                        </flux:badge>
                    </div>

                    {{-- Status / progress --}}
                    <div class="min-w-0 space-y-2">

                        @if ($isInherited)
                            <flux:text size="sm">
                                Warm-up result inherited from the simulation baseline.
                            </flux:text>
                        @elseif ($failedAttempts->isNotEmpty())
                            <div class="space-y-1">
                                @foreach ($failedAttempts as $attempt)
                                    <p class="text-sm text-red-700 dark:text-red-300">
                                        Quarter {{ $quarter->quarter_number }}
                                        · {{ $attempt->firm->code }}
                                        · {{ ucfirst($attempt->safe_error['category'] ?? 'system') }}:
                                        {{ $attempt->safe_error['message'] ?? 'Close failed.' }}
                                    </p>
                                @endforeach
                            </div>

                            <flux:text size="sm">
                                {{ $successfulAttempts->count() }}
                                /
                                {{ $totalAttempts }}
                                firms complete.
                            </flux:text>
                        @elseif ($quarter->state === 'processing')
                            <flux:text size="sm">
                                Close is processing.
                                {{ $successfulAttempts->count() }}
                                /
                                {{ $totalAttempts }}
                                firms complete.
                            </flux:text>
                        @elseif ($quarter->state === 'closed')
                            <flux:text size="sm">
                                All firms completed the quarter close successfully.
                            </flux:text>
                        @elseif ($quarter->state === 'open')
                            <flux:text size="sm">
                                Quarter is open for student decisions.
                            </flux:text>
                        @else
                            <flux:text size="sm">
                                No close attempts recorded.
                            </flux:text>
                        @endif

                        @if ($diagnosticAttempts->isNotEmpty())
                            <div class="space-y-1">

                                @foreach ($diagnosticAttempts as $attempt)
                                    @foreach ($attempt->diagnostic_detail['operating_outcome_violations'] ?? [] as $violation)
                                        <p class="text-sm text-amber-700 dark:text-amber-300">
                                            Quarter {{ $quarter->quarter_number }}
                                            · {{ $attempt->firm->code }}
                                            · Plausibility:
                                            {{ $violation['rule'] ?? 'operating outcome' }}
                                            · Calculated:
                                            {{ $violation['calculated'] ?? 'n/a' }}
                                            · Allowed:
                                            {{ $violation['allowed_range'][0] ?? 'n/a' }}
                                            – {{ $violation['allowed_range'][1] ?? 'n/a' }}
                                        </p>
                                    @endforeach
                                @endforeach

                            </div>
                        @endif
                    </div>

                    {{-- Actions --}}
                    <div class="flex flex-wrap gap-2 md:justify-end">

                        @if ($canOpen)
                            <flux:button wire:click="open('{{ $quarter->id }}')" wire:loading.attr="disabled"
                                wire:target="open('{{ $quarter->id }}')" icon="lock-open" variant="primary">
                                Open
                            </flux:button>
                        @endif

                        @if ($quarter->state === 'open')
                            <flux:button wire:click="close('{{ $quarter->id }}')" wire:loading.attr="disabled"
                                wire:target="close('{{ $quarter->id }}')" icon="lock-closed" variant="primary">
                                Close
                            </flux:button>
                        @endif

                        @if ($quarter->state === 'processing' && $failedAttempts->isNotEmpty())
                            <flux:button wire:click="close('{{ $quarter->id }}')" wire:loading.attr="disabled"
                                wire:target="close('{{ $quarter->id }}')" icon="arrow-path" variant="primary">
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
        <span>Processing request...</span>
    </div>
</div>
