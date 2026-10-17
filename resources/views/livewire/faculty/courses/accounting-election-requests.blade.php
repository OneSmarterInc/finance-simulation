<div class="flexee-page">

    <x-page-header
        :eyebrow="$course->name"
        title="Accounting Election Requests"
        description="Review student-proposed accounting method changes for this course."
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


    {{-- Success message --}}
    @if (session('success'))
        <flux:callout
            variant="success"
            icon="check-circle"
            :heading="session('success')"
            role="status"
        />
    @endif


    {{-- ============================================================
         CONDITION 1
         Accounting Election Control Disabled
         ============================================================ --}}
    @if (! $course->accounting_election_enabled)

        <flux:card class="border-zinc-200 dark:border-white/10">

            <div class="flex items-start gap-4">

                <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-white/10">
                    <flux:icon
                        name="information-circle"
                        class="size-5 text-zinc-500"
                    />
                </div>

                <div class="min-w-0">

                    <flux:heading size="lg">
                        Accounting Election Control Disabled
                    </flux:heading>

                    <flux:text class="mt-1">
                        Student accounting-election control is currently disabled
                        for this course.
                    </flux:text>

                    <flux:text class="mt-2 text-sm text-zinc-500">
                        Students will use the simulation's configured default
                        accounting settings. No accounting-election requests
                        or faculty approvals are required.
                    </flux:text>

                </div>

            </div>

        </flux:card>


    {{-- ============================================================
         CONDITION 2
         Accounting Election Enabled + No Requests
         ============================================================ --}}
    @elseif ($requests->isEmpty())

        <flux:card class="border-zinc-200 dark:border-white/10">

            <div class="flex flex-col items-center px-6 py-10 text-center">

                <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-white/10">

                    <flux:icon
                        name="document-text"
                        class="size-6 text-zinc-500"
                    />

                </div>

                <flux:heading size="lg" class="mt-4">
                    No Accounting Election Requests
                </flux:heading>

                <flux:text class="mt-2 max-w-xl">
                    No students have submitted an accounting-method change
                    request for this course yet.
                </flux:text>

                <flux:text class="mt-1 max-w-xl text-sm text-zinc-500">
                    Student requests will appear here when they choose to
                    change the default accounting settings.
                </flux:text>

            </div>

        </flux:card>


    {{-- ============================================================
         CONDITION 3
         Accounting Election Enabled + Requests
         ============================================================ --}}
    @else

        @php
            $pendingCount = $requests->where('status', 'pending')->count();
            $approvedCount = $requests->where('status', 'approved')->count();
            $rejectedCount = $requests->where('status', 'rejected')->count();
        @endphp


        {{-- Request summary --}}
        <div class="grid gap-3 sm:grid-cols-3">

            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-400/20 dark:bg-amber-400/5">

                <div class="flex items-center justify-between gap-3">

                    <div>
                        <flux:text class="text-xs font-medium uppercase tracking-wide text-amber-700 dark:text-amber-400">
                            Pending
                        </flux:text>

                        <div class="mt-1 text-2xl font-semibold text-amber-900 dark:text-amber-300">
                            {{ $pendingCount }}
                        </div>
                    </div>

                    <flux:icon
                        name="clock"
                        class="size-5 text-amber-600 dark:text-amber-400"
                    />

                </div>

            </div>


            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-400/20 dark:bg-emerald-400/5">

                <div class="flex items-center justify-between gap-3">

                    <div>
                        <flux:text class="text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-400">
                            Approved
                        </flux:text>

                        <div class="mt-1 text-2xl font-semibold text-emerald-900 dark:text-emerald-300">
                            {{ $approvedCount }}
                        </div>
                    </div>

                    <flux:icon
                        name="check-circle"
                        class="size-5 text-emerald-600 dark:text-emerald-400"
                    />

                </div>

            </div>


            <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-white/10 dark:bg-white/5">

                <div class="flex items-center justify-between gap-3">

                    <div>
                        <flux:text class="text-xs font-medium uppercase tracking-wide text-zinc-500">
                            Rejected
                        </flux:text>

                        <div class="mt-1 text-2xl font-semibold text-zinc-800 dark:text-zinc-200">
                            {{ $rejectedCount }}
                        </div>
                    </div>

                    <flux:icon
                        name="x-circle"
                        class="size-5 text-zinc-500"
                    />

                </div>

            </div>

        </div>


        {{-- Requests --}}
        <div class="space-y-4">

            @foreach ($requests as $request)

                @php
                    $proposed = $request->proposed_elections ?? [];
                @endphp


                <flux:card class="overflow-hidden p-0">

                    {{-- Request header --}}
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-white/10">

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">

                            <div class="min-w-0">

                                <div class="flex flex-wrap items-center gap-2">

                                    <flux:heading size="lg">
                                        {{ $request->student?->user?->name ?? 'Student' }}
                                    </flux:heading>

                                    @if ($request->status === 'pending')
                                        <flux:badge color="amber">
                                            Pending Review
                                        </flux:badge>
                                    @elseif ($request->status === 'approved')
                                        <flux:badge color="emerald">
                                            Approved
                                        </flux:badge>
                                    @else
                                        <flux:badge color="zinc">
                                            Rejected
                                        </flux:badge>
                                    @endif

                                </div>

                                <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm text-zinc-500">

                                    <span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                            Firm:
                                        </span>
                                        {{ $request->firm?->name ?? '—' }}
                                    </span>

                                    <span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                            Quarter:
                                        </span>
                                        {{ $request->courseQuarter?->quarter_number ?? '—' }}
                                    </span>

                                    <span>
                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                            Submitted:
                                        </span>
                                        {{ $request->created_at?->format('d M Y, h:i A') ?? '—' }}
                                    </span>

                                </div>

                            </div>

                        </div>

                    </div>


                    {{-- Proposed settings --}}
                    <div class="px-5 py-5">

                        <div class="mb-3 flex items-center justify-between gap-4">

                            <flux:heading size="sm">
                                Proposed Accounting Settings
                            </flux:heading>

                            <flux:text class="text-xs text-zinc-500">
                                Inventory is fixed
                            </flux:text>

                        </div>


                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-white/10">

                            <dl class="divide-y divide-zinc-200 dark:divide-white/10">

                                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-2 sm:gap-4">
                                    <dt class="text-sm text-zinc-500">
                                        Depreciation Method
                                    </dt>

                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ str($proposed['depreciation_method'] ?? '—')->replace('_', ' ')->title() }}
                                    </dd>
                                </div>


                                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-2 sm:gap-4">
                                    <dt class="text-sm text-zinc-500">
                                        Shipping Terms
                                    </dt>

                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ str($proposed['shipping_terms'] ?? '—')->replace('_', ' ')->title() }}
                                    </dd>
                                </div>


                                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-2 sm:gap-4">
                                    <dt class="text-sm text-zinc-500">
                                        Bad Debt Method
                                    </dt>

                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ str($proposed['bad_debt_method'] ?? '—')->replace('_', ' ')->title() }}
                                    </dd>
                                </div>


                                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-2 sm:gap-4">
                                    <dt class="text-sm text-zinc-500">
                                        Warranty Method
                                    </dt>

                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ str($proposed['warranty_method'] ?? '—')->replace('_', ' ')->title() }}
                                    </dd>
                                </div>


                                <div class="grid grid-cols-1 gap-1 px-4 py-3 sm:grid-cols-2 sm:gap-4">
                                    <dt class="text-sm text-zinc-500">
                                        Excise Tax Treatment
                                    </dt>

                                    <dd class="text-sm font-medium text-zinc-900 dark:text-white">
                                        {{ str($proposed['excise_tax_treatment'] ?? '—')->replace('_', ' ')->title() }}
                                    </dd>
                                </div>


                                <div class="grid grid-cols-1 gap-1 bg-zinc-50 px-4 py-3 sm:grid-cols-2 sm:gap-4 dark:bg-white/5">
                                    <dt class="text-sm font-medium text-zinc-500">
                                        Inventory Method
                                    </dt>

                                    <dd class="text-sm font-semibold text-zinc-900 dark:text-white">
                                        FIFO
                                        <span class="ml-1 text-xs font-normal text-zinc-500">
                                            (Fixed)
                                        </span>
                                    </dd>
                                </div>

                            </dl>

                        </div>

                    </div>


                    {{-- Actions --}}
                    @if ($request->status === 'pending')

                        <div class="flex flex-col-reverse gap-2 border-t border-zinc-200 bg-zinc-50 px-5 py-4 dark:border-white/10 dark:bg-white/5 sm:flex-row sm:justify-end">

                            <flux:button
                                type="button"
                                wire:click="reject('{{ $request->id }}')"
                                wire:confirm="Reject this accounting-election change request?"
                            >
                                Reject
                            </flux:button>

                            <flux:button
                                type="button"
                                wire:click="approve('{{ $request->id }}')"
                                wire:confirm="Approve this accounting-election change request?"
                                variant="primary"
                            >
                                Approve
                            </flux:button>

                        </div>

                    @else

                        <div class="flex items-center justify-between gap-4 border-t border-zinc-200 bg-zinc-50 px-5 py-3 dark:border-white/10 dark:bg-white/5">

                            <flux:text class="text-xs text-zinc-500">
                                Reviewed
                                {{ $request->reviewed_at?->format('d M Y, h:i A') ?? '—' }}
                                @if ($request->reviewedByFaculty?->user?->name)
                                    by {{ $request->reviewedByFaculty->user->name }}
                                @endif
                            </flux:text>

                            <flux:text class="text-xs font-medium">
                                {{ str($request->status)->title() }}
                            </flux:text>

                        </div>

                    @endif

                </flux:card>

            @endforeach

        </div>

    @endif

</div>