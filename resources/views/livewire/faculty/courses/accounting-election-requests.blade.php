@php
    use App\Support\Dates;
    use App\Support\Presentation\AccountingMethodGuide as Guide;
    use App\Support\Presentation\DecisionPresenter as Names;
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Accounting method requests"
        description="Teams ask to change how their firm reports. Each request comes with the team's reason; nothing changes until you approve it." />

    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" :heading="session('success')" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    @if (! $course->accounting_election_enabled)
        <flux:callout icon="information-circle" heading="Teams can't ask to change their accounting methods in this course"
            text="Every firm reports with the default methods, so there are no requests to review." />
    @else
        {{-- U12 (U5): the instructor's switch for Claude's review. --}}
        @if ($reviewsReady)
            <flux:card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="review-switch">
                <div class="space-y-1">
                    <flux:heading level="2">Claude's review of each request</flux:heading>
                    <flux:text size="sm" class="max-w-2xl">
                        When a team files a request, Claude weighs its reason against the tests for a change in accounting principle and drafts a reply. Only you see it, and you always decide.
                    </flux:text>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <flux:badge size="sm" :color="$course->ai_request_review ? 'emerald' : 'zinc'">{{ $course->ai_request_review ? 'Shown to you' : 'Switched off' }}</flux:badge>
                    <flux:button size="sm" wire:click="toggleReview" data-test="review-toggle">{{ $course->ai_request_review ? 'Switch off' : 'Switch on' }}</flux:button>
                </div>
            </flux:card>
        @endif

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
                    <p class="mt-1">{{ $request->justification ?: 'No reason given: filed before reasons were required.' }}</p>
                </div>

                {{-- U12 (RV1): Claude's review, after the team's reason. Faculty only. --}}
                @if ($reviewsOn)
                    @php($review = $request->claudeReview)
                    <section class="rounded-md border border-sky-200 bg-sky-50/70 p-4 dark:border-sky-400/20 dark:bg-sky-400/5" data-test="claude-review">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <flux:icon.sparkles variant="micro" class="text-sky-700 dark:text-sky-300" />
                                <h3 class="text-sm font-semibold">Claude's review</h3>
                            </div>
                            @if ($review?->isWritten())
                                <flux:badge size="sm" :color="['approve' => 'emerald', 'decline' => 'red', 'ask' => 'sky'][$review->recommendation] ?? 'zinc'">
                                    Suggests: {{ \App\Models\AccountingRequestReview::RECOMMENDATIONS[$review->recommendation] ?? $review->recommendation }}
                                </flux:badge>
                            @endif
                        </div>
                        @if ($review?->isWritten())
                            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-800 dark:text-zinc-200">
                                @foreach ((array) $review->reasons as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                Written when the request was filed, from the team's reason and the firm's stored results.{{ filled($review->reply) ? ' Claude\'s draft reply is in the note box below, for you to edit.' : '' }} You decide.
                            </p>
                        @elseif ($review && $review->attempts >= \App\Services\Ai\RequestReviews::MAX_ATTEMPTS)
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">Claude's review of this request couldn't be written. The request works as before.</p>
                        @else
                            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300" data-test="review-waiting">Claude is reviewing this request. Its review appears here within a few minutes.</p>
                        @endif
                    </section>
                @endif

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

                <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-white/10">
                    <flux:textarea wire:model="notes.{{ $request->id }}" rows="{{ $reviewsOn ? 4 : 2 }}" label="Note to the team if you decline or send it back"
                        placeholder="For example: the reason doesn't explain why straight-line misstates this plant." />
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:button variant="primary" icon="check" wire:click="approve('{{ $request->id }}')"
                            wire:confirm="{{ $request->firm->name }}'s methods change from Quarter {{ $row['target']?->quarter_number }} onward. Approve?">
                            Approve
                        </flux:button>
                        <div class="flex flex-wrap gap-2">
                            <flux:button icon="x-mark" wire:click="reject('{{ $request->id }}')"
                                wire:confirm="Decline {{ $request->firm->name }}'s request? The team sees your note and can file again next quarter.">
                                Decline
                            </flux:button>
                            <flux:button icon="arrow-uturn-left" wire:click="sendBack('{{ $request->id }}')" data-test="send-back"
                                wire:confirm="Send {{ $request->firm->name }}'s request back? The team sees your note and can file again this quarter.">
                                Send back
                            </flux:button>
                        </div>
                    </div>
                </div>
                @if (! $request->justification && ! $reviewsOn)
                    {{-- L17: approving would disclose the change with an empty reason. --}}
                    <div class="flex flex-col gap-2 rounded-lg bg-zinc-50 p-3 text-sm sm:flex-row sm:items-center sm:justify-between dark:bg-white/5" data-test="ask-for-reason">
                        <p>Approving would disclose the change in the notes with no reason. You can ask the team for one instead. They'll see: <span class="italic">"{{ \App\Services\Accounting\FirmAccountingElectionRequestService::reasonNote($request, $row['current']) }}"</span> and can file again this quarter.</p>
                        <flux:button size="sm" icon="chat-bubble-left-ellipsis" class="shrink-0" wire:click="askForReason('{{ $request->id }}')">Ask the team for their reason</flux:button>
                    </div>
                @endif
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
                        {{ match ($request->status) { 'approved' => 'Approved'.($request->effective_quarter_number ? ', from Quarter '.$request->effective_quarter_number : ''), 'returned' => $request->justification ? 'Sent back to the team' : 'Asked for their reason', default => 'Declined' } }}
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
