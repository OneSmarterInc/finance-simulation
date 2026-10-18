@php
    use App\Support\Dates;
    use App\Support\Presentation\AccountingMethodGuide as Guide;
    use App\Support\Presentation\DecisionPresenter as Names;
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Accounting elections"
        description="Teams ask to change how their firm reports. Each request comes with the team's reason; nothing changes until you approve it." />

    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" :heading="session('success')" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    @if (! $course->accounting_election_enabled)
        <flux:callout icon="information-circle" heading="Student accounting-election control is off for this course"
            text="Every firm reports with the default methods, so there are no requests to review." />
    @else
        <flux:heading size="lg" level="2">Waiting for you ({{ $pending->count() }})</flux:heading>

        @forelse ($pending as $row)
            @php($request = $row['request'])
            <flux:card class="space-y-4" wire:key="request-{{ $request->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">{{ $request->firm->name }}</flux:heading>
                        <flux:text size="sm">
                            Filed for the team by {{ $request->student?->user?->name }}, {{ Dates::dateTime($request->created_at) }}, during Quarter {{ $request->courseQuarter?->quarter_number }}.
                        </flux:text>
                    </div>
                    <flux:badge color="amber">Waiting</flux:badge>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-300 text-left text-xs text-zinc-500 dark:border-zinc-600">
                                <th class="py-2 pr-4">Method</th><th class="py-2 pr-4">Now</th><th class="py-2 pr-4">Requested</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                            @foreach ($request->proposed_elections as $key => $value)
                                @php($changed = ($row['current'][$key] ?? null) !== $value)
                                <tr @class(['bg-amber-50 dark:bg-amber-400/10' => $changed])>
                                    <th scope="row" class="py-2 pr-4 text-left font-medium align-top">{{ Names::fieldLabel($key) }}</th>
                                    <td class="py-2 pr-4 align-top">{{ Names::valueLabel($key, $row['current'][$key] ?? null) }}</td>
                                    <td class="py-2 pr-4 align-top">
                                        <span @class(['font-semibold' => $changed])>{{ Names::valueLabel($key, $value) }}</span>
                                        @if ($changed)
                                            <span class="block text-xs text-zinc-600 dark:text-zinc-400">{{ Guide::effect($key, $value) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="rounded-md bg-zinc-50 p-3 text-sm dark:bg-white/5">
                    <p class="font-medium">The team's reason</p>
                    <p class="mt-1">{{ $request->justification ?: 'No reason was given with this request.' }}</p>
                </div>

                <p class="text-sm">
                    @if ($row['target'] && $row['target']->quarter_number === $request->courseQuarter?->quarter_number)
                        @if ($row['target']->decision_deadline_at?->isPast())
                            If you approve now, the change applies to Quarter {{ $row['target']->quarter_number }}'s results and every quarter after. Quarter {{ $row['target']->quarter_number }} was due to close on {{ Dates::dateTime($row['target']->decision_deadline_at) }} and is still open.
                        @else
                            If you approve now, the change applies to Quarter {{ $row['target']->quarter_number }}'s results (it closes {{ Dates::dateTime($row['target']->decision_deadline_at) }}) and every quarter after.
                        @endif
                    @elseif ($row['target'])
                        Quarter {{ $request->courseQuarter?->quarter_number }} has closed, so if you approve now the change applies from Quarter {{ $row['target']->quarter_number }}.
                    @else
                        No quarter is left for this change to apply to.
                    @endif
                    That quarter's notes will disclose the change and the team's reason.
                </p>

                <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4 sm:flex-row sm:items-end dark:border-white/10">
                    <flux:button variant="primary" icon="check" wire:click="approve('{{ $request->id }}')"
                        wire:confirm="{{ $request->firm->name }}'s methods change from Quarter {{ $row['target']?->quarter_number }} onward. Approve?">
                        Approve
                    </flux:button>
                    <div class="flex-1">
                        <flux:input wire:model="notes.{{ $request->id }}" size="sm" label="Note to the team if you decline"
                            placeholder="For example: the reason doesn't explain why straight-line misstates this plant." />
                    </div>
                    <flux:button icon="x-mark" wire:click="reject('{{ $request->id }}')"
                        wire:confirm="Decline {{ $request->firm->name }}'s request? The team sees your note and can file again next quarter.">
                        Decline
                    </flux:button>
                </div>
            </flux:card>
        @empty
            <flux:text>No requests are waiting.</flux:text>
        @endforelse

        @if ($decided->isNotEmpty())
            <flux:heading size="lg" level="2" class="pt-4">Decided</flux:heading>
            <flux:card class="space-y-2">
                @foreach ($decided as $row)
                    @php($request = $row['request'])
                    <p class="text-sm">
                        <span class="font-medium">{{ $request->firm->name }}</span> ·
                        Quarter {{ $request->courseQuarter?->quarter_number }} ·
                        {{ $request->status === 'approved' ? 'Approved'.($request->effective_quarter_number ? ', from Quarter '.$request->effective_quarter_number : '') : 'Declined' }}
                        {{ $request->reviewed_at ? '· '.Dates::day($request->reviewed_at) : '' }} ·
                        @foreach ($row['changes'] as $change)
                            {{ $change['label'] }} to {{ $change['to'] }}@if (! $loop->last), @endif
                        @endforeach
                        @if ($request->faculty_note) · “{{ $request->faculty_note }}” @endif
                    </p>
                @endforeach
            </flux:card>
        @endif
    @endif
</div>
