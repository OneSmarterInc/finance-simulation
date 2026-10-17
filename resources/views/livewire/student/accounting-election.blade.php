<div class="mx-auto max-w-5xl space-y-4 px-3 py-4 sm:px-4 lg:px-6">

    {{-- Page Header --}}
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            Accounting Elections
        </h1>

        <p class="mt-1 text-sm text-gray-600">
            Quarter {{ $quarter->quarter_number }} accounting settings for
            {{ $firm->name }}.
        </p>
    </div>


    {{-- Success Message --}}
    @if (session()->has('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-3">

            <div class="flex items-start gap-3">

                <flux:icon name="check-circle" class="mt-0.5 size-5 text-green-600" />

                <p class="text-sm font-medium text-green-800">
                    {{ session('success') }}
                </p>

            </div>

        </div>
    @endif


    {{-- ========================================================= --}}
    {{-- 1. CURRENT ACCOUNTING SETTINGS                           --}}
    {{-- ========================================================= --}}

    <details open class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

        <summary class="flex cursor-pointer list-none items-center justify-between p-4">

            <div>

                <h2 class="text-lg font-semibold text-gray-900">
                    Current Accounting Settings
                </h2>

                @if ($firm->course->accounting_election_enabled)

                    @if (collect($effectiveSettings)->except('inventory_method')->values()->contains(fn($value) => !is_null($value)))
                        <p class="mt-1 text-sm text-gray-600">
                            These are the accounting settings currently effective
                            for this firm.
                        </p>
                    @else
                        <p class="mt-1 text-sm text-gray-600">
                            The simulation is using its configured default
                            accounting settings.
                        </p>
                    @endif
                @else
                    <p class="mt-1 text-sm text-gray-600">
                        The simulation is using its configured default
                        accounting settings.
                    </p>

                @endif

            </div>

            <flux:icon name="chevron-down"
                class="size-5 shrink-0 text-gray-500 transition-transform group-open:rotate-180" />

        </summary>


        <div class="border-t border-gray-200 p-4">

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">

                @foreach ($fields as $field)
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">

                        <dt class="text-sm font-medium text-gray-500">
                            {{ $field['label'] }}
                        </dt>

                        <dd class="mt-1 text-base font-semibold text-gray-900">
                            {{ \Illuminate\Support\Str::of($effectiveSettings[$field['key']] ?? ($field['default'] ?? ''))->replace('_', ' ')->title() }}
                        </dd>

                    </div>
                @endforeach


                {{-- Inventory is always FIFO --}}
                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">

                    <dt class="text-sm font-medium text-gray-500">
                        Inventory Method
                    </dt>

                    <dd class="mt-1 text-base font-semibold text-gray-900">
                        FIFO
                    </dd>

                    <p class="mt-1 text-xs text-gray-500">
                        Fixed and cannot be changed.
                    </p>

                </div>

            </div>

        </div>

    </details>


    {{-- ========================================================= --}}
    {{-- 2. ACCOUNTING SETTINGS                                    --}}
    {{-- ========================================================= --}}

    <details open class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

        <summary class="flex cursor-pointer list-none items-center justify-between p-4">

            <div>

                <h2 class="text-lg font-semibold text-gray-900">
                    Accounting Settings
                </h2>

                @if (!$firm->course->accounting_election_enabled)
                    <p class="mt-1 text-sm text-gray-600">
                        Accounting-election control is disabled for this course.
                    </p>
                @elseif ($requests->contains(fn($request) => $request->status === 'approved'))
                    <p class="mt-1 text-sm text-gray-600">
                        An approved accounting election is currently effective.
                    </p>
                @elseif ($requests->contains(fn($request) => $request->status === 'pending'))
                    <p class="mt-1 text-sm text-gray-600">
                        A request is awaiting faculty review. You may still
                        submit your own change request.
                    </p>
                @else
                    <p class="mt-1 text-sm text-gray-600">
                        Use the default accounting settings or propose different
                        accounting methods for faculty review.
                    </p>
                @endif

            </div>

            <flux:icon name="chevron-down"
                class="size-5 shrink-0 text-gray-500 transition-transform group-open:rotate-180" />

        </summary>


        <div class="border-t border-gray-200 p-4">

            {{-- ================================================= --}}
            {{-- Feature disabled                                 --}}
            {{-- ================================================= --}}

            @if (!$firm->course->accounting_election_enabled)

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">

                    <div class="flex items-start gap-3">

                        <flux:icon name="information-circle" class="mt-0.5 size-5 text-gray-500" />

                        <div>

                            <h3 class="font-semibold text-gray-900">
                                Default Accounting Settings
                            </h3>

                            <p class="mt-1 text-sm text-gray-600">
                                This simulation uses the configured accounting
                                methods. No accounting-method change request
                                or faculty approval is required.
                            </p>

                        </div>

                    </div>

                </div>


                {{-- ================================================= --}}
                {{-- Approved request exists                          --}}
                {{-- ================================================= --}}
            @elseif ($requests->contains(fn($request) => $request->status === 'approved'))
                <div class="rounded-lg border border-green-200 bg-green-50 p-3">

                    <div class="flex items-start gap-3">

                        <flux:icon name="check-circle" class="mt-0.5 size-5 text-green-600" />

                        <div>

                            <h3 class="font-semibold text-gray-900">
                                Current Accounting Settings Are Approved
                            </h3>

                            <p class="mt-1 text-sm text-gray-600">
                                The accounting settings shown above are the
                                settings currently effective for this firm.
                            </p>

                        </div>

                    </div>

                </div>


                {{-- ================================================= --}}
                {{-- Feature enabled and no approved request          --}}
                {{-- ================================================= --}}
            @else
                {{-- Pending request notice --}}
                @if ($requests->contains(fn($request) => $request->status === 'pending'))
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3">

                        <div class="flex items-start gap-3">

                            <flux:icon name="clock" class="mt-0.5 size-5 text-amber-600" />

                            <div>

                                <h3 class="font-semibold text-gray-900">
                                    Accounting Change Request Pending
                                </h3>

                                <p class="mt-1 text-sm text-gray-600">
                                    A request from a member of this firm is
                                    currently awaiting faculty review.
                                    You may still submit your own
                                    accounting-method change request.
                                </p>

                            </div>

                        </div> 
                    </div>
                @endif

                    
                {{-- Default choice --}}
                @if ($choice === 'default')

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">

                        <div class="flex items-start gap-3">

                            <flux:icon name="information-circle" class="mt-0.5 size-5 text-gray-500" />

                            <div>

                                <h3 class="font-semibold text-gray-900">
                                    Use Default Accounting Settings
                                </h3>

                                <p class="mt-1 text-sm text-gray-600">
                                    Continue with the accounting settings
                                    configured for this simulation.
                                    No faculty approval is required.
                                </p>

                            </div>

                        </div>


                        <div class="mt-4">

                            <h4 class="text-sm font-semibold text-gray-700">
                                Default Accounting Methods
                            </h4>

                            <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2">

                                @foreach ($fields as $field)
                                    <div class="rounded-md border border-gray-200 bg-white px-3 py-2">

                                        <p class="text-xs font-medium text-gray-500">
                                            {{ $field['label'] }}
                                        </p>

                                        <p class="mt-0.5 text-sm font-semibold text-gray-900">
                                            {{ \Illuminate\Support\Str::of($defaultSettings[$field['key']] ?? '')->replace('_', ' ')->title() }}
                                        </p>

                                    </div>
                                @endforeach


                                <div class="rounded-md border border-gray-200 bg-white px-3 py-2">

                                    <p class="text-xs font-medium text-gray-500">
                                        Inventory Method
                                    </p>

                                    <p class="mt-0.5 text-sm font-semibold text-gray-900">
                                        FIFO
                                    </p>

                                </div>

                            </div>

                        </div>


                        <div class="mt-4">

                            <flux:button type="button" wire:click="selectChange" variant="primary">
                                Change Accounting Settings
                            </flux:button>

                        </div>

                    </div>


                    {{-- Change form --}}
                @else
                    <div>

                        <div class="mb-4">

                            <h3 class="text-lg font-semibold text-gray-900">
                                Change Accounting Settings
                            </h3>

                            <p class="mt-1 text-sm text-gray-600">
                                Select the accounting methods you want to
                                propose for faculty approval.
                            </p>

                        </div>


                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">

                            @foreach ($fields as $field)
                                <div>

                                    <label class="block text-sm font-medium text-gray-700">
                                        {{ $field['label'] }}
                                    </label>

                                    <select wire:model="election.{{ $field['key'] }}"
                                        class="mt-1 block w-full rounded-md border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-500 focus:ring-gray-500">

                                        <option value="">
                                            Select {{ $field['label'] }}
                                        </option>

                                        @foreach ($field['allowed_values'] as $value)
                                            <option value="{{ $value }}">
                                                {{ \Illuminate\Support\Str::of($value)->replace('_', ' ')->title() }}
                                            </option>
                                        @endforeach

                                    </select>

                                </div>
                            @endforeach


                            {{-- Inventory remains fixed --}}
                            <div>

                                <label class="block text-sm font-medium text-gray-700">
                                    Inventory Method
                                </label>

                                <div
                                    class="mt-1 rounded-md border border-gray-200 bg-gray-50 px-3 py-2 text-sm font-semibold text-gray-900">
                                    FIFO
                                </div>

                                <p class="mt-1 text-xs text-gray-500">
                                    Fixed and cannot be changed.
                                </p>

                            </div>

                        </div>


                        @error('election')
                            <p class="mt-3 text-sm text-red-600">
                                {{ $message }}
                            </p>
                        @enderror


                        <div class="mt-4 flex gap-2">

                            <flux:button type="button" wire:click="selectDefault" variant="outline">
                                Cancel Change
                            </flux:button>

                            <flux:button type="button" wire:click="save" variant="primary">
                                Submit for Faculty Review
                            </flux:button>

                        </div>

                    </div>

                @endif

            @endif

        </div>

    </details>

    {{-- ========================================================= --}}
    {{-- 3. ACCOUNTING ELECTION REQUEST HISTORY                   --}}
    {{-- ========================================================= --}}

    @if ($firm->course->accounting_election_enabled)

        {{-- Feature enabled: normal request history --}}
        <details class="group overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">

            <summary class="flex cursor-pointer list-none items-center justify-between p-5">

                <div>

                    <h2 class="text-lg font-semibold text-gray-900">
                        Accounting Election Request History
                    </h2>

                    <p class="mt-1 text-sm text-gray-600">
                        All accounting-election requests submitted by members
                        of this firm for Quarter {{ $quarter->quarter_number }}.
                    </p>

                </div>

                <flux:icon name="chevron-down"
                    class="size-5 shrink-0 text-gray-500 transition-transform group-open:rotate-180" />

            </summary>


            <div class="border-t border-gray-200 p-5">

                @if ($requests->isEmpty())

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-5">

                        <div class="flex items-start gap-3">

                            <flux:icon name="document-text" class="mt-0.5 size-5 text-gray-500" />

                            <div>

                                <h3 class="font-semibold text-gray-900">
                                    No Accounting Election Requests
                                </h3>

                                <p class="mt-1 text-sm text-gray-600">
                                    No accounting-method change requests have
                                    been submitted for this firm in Quarter
                                    {{ $quarter->quarter_number }}.
                                </p>

                            </div>

                        </div>

                    </div>
                @else
                    <div class="space-y-4">

                        @foreach ($requests as $request)
                            @php

                                $proposed = $request->proposed_elections ?? [];

                                $statusClasses = match ($request->status) {
                                    'approved' => 'bg-green-100 text-green-800',
                                    'rejected' => 'bg-red-100 text-red-800',
                                    default => 'bg-amber-100 text-amber-800',
                                };

                                $statusLabel = match ($request->status) {
                                    'approved' => 'Approved',
                                    'rejected' => 'Rejected',
                                    default => 'Pending Faculty Review',
                                };

                            @endphp


                            <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">

                                {{-- Request Header --}}
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">

                                    <div>

                                        <h3 class="font-semibold text-gray-900">
                                            Accounting Change Request
                                        </h3>

                                        <p class="mt-1 text-sm text-gray-600">
                                            Submitted by
                                            <span class="font-medium text-gray-900">
                                                {{ $request->student?->user?->name ?? 'Unknown Student' }}
                                            </span>
                                        </p>

                                        <p class="text-sm text-gray-500">
                                            Firm:
                                            {{ $request->firm?->name ?? '—' }}
                                        </p>

                                        <p class="text-sm text-gray-500">
                                            Quarter
                                            {{ $request->courseQuarter?->quarter_number ?? '—' }}
                                        </p>

                                        <p class="text-sm text-gray-500">
                                            {{ $request->created_at?->format('d M Y, h:i A') }}
                                        </p>

                                    </div>


                                    <span
                                        class="inline-flex w-fit rounded-full px-3 py-1 text-xs font-semibold uppercase {{ $statusClasses }}">
                                        {{ $statusLabel }}
                                    </span>

                                </div>


                                {{-- Proposed Settings --}}
                                <div class="mt-5">

                                    <h4 class="text-sm font-semibold text-gray-700">
                                        Proposed Accounting Settings
                                    </h4>

                                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">

                                        @foreach ($fields as $field)
                                            <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">

                                                <p class="text-xs font-medium text-gray-500">
                                                    {{ $field['label'] }}
                                                </p>

                                                <p class="mt-1 text-sm font-semibold text-gray-900">
                                                    {{ \Illuminate\Support\Str::of($proposed[$field['key']] ?? '')->replace('_', ' ')->title() }}
                                                </p>

                                            </div>
                                        @endforeach


                                        {{-- Inventory --}}
                                        <div class="rounded-md border border-gray-200 bg-gray-50 px-3 py-2">

                                            <p class="text-xs font-medium text-gray-500">
                                                Inventory Method
                                            </p>

                                            <p class="mt-1 text-sm font-semibold text-gray-900">
                                                FIFO
                                            </p>

                                            <p class="mt-1 text-xs text-gray-500">
                                                Fixed and cannot be changed.
                                            </p>

                                        </div>

                                    </div>

                                </div>


                                {{-- Review Information --}}
                                @if ($request->reviewed_at)
                                    <div class="mt-4 border-t border-gray-200 pt-3">

                                        <p class="text-xs text-gray-500">

                                            Reviewed
                                            {{ $request->reviewed_at->format('d M Y, h:i A') }}

                                            @if ($request->reviewedByFaculty?->user?->name)
                                                by
                                                <span class="font-medium text-gray-700">
                                                    {{ $request->reviewedByFaculty->user->name }}
                                                </span>
                                            @endif

                                        </p>

                                    </div>
                                @endif

                            </div>
                        @endforeach

                    </div>

                @endif

            </div>

        </details>
    @else
        {{-- Feature disabled: visible but greyed out --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50 opacity-60">

            <div class="flex items-center justify-between p-5">

                <div>

                    <h2 class="text-lg font-semibold text-gray-500">
                        Accounting Election Request History
                    </h2>

                    <p class="mt-1 text-sm text-gray-400">
                        Request history is unavailable because Accounting
                        Election Control is disabled for this simulation.
                    </p>

                </div>

                <flux:icon name="lock-closed" class="size-5 shrink-0 text-gray-400" />

            </div>

        </div>

    @endif


</div>
