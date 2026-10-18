@php
    use App\Support\Dates;
    use App\Support\Presentation\AccountingMethodGuide as Guide;
    use App\Support\Presentation\DecisionPresenter as Names;
    use App\Services\Accounting\AccountingChangeDisclosures;
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$firm->course->name" :title="$firm->name.' accounting methods'"
        description="How your firm measures and reports its results. A change of method moves reported figures without moving cash, which is exactly what your statements will let you see." />

    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" :heading="session('success')" role="status" />
    @endif

    {{-- Current methods, shown once (item 84) --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg" level="2">Methods your firm uses in Quarter {{ $quarter->quarter_number }}</flux:heading>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @foreach (['inventory_method', 'depreciation_method', 'shipping_terms', 'bad_debt_method', 'warranty_method', 'excise_tax_treatment'] as $key)
                        <tr>
                            <th scope="row" class="w-48 py-2 pr-4 text-left font-medium align-top">{{ Names::fieldLabel($key) }}</th>
                            <td class="w-56 py-2 pr-4 align-top">{{ Names::valueLabel($key, $current[$key] ?? null) }}</td>
                            <td class="py-2 text-zinc-600 dark:text-zinc-400 align-top">{{ Guide::effect($key, (string) ($current[$key] ?? '')) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </flux:card>

    @if (! $firm->course->accounting_election_enabled)
        <flux:callout icon="information-circle" heading="Your instructor has fixed the methods for this course"
            text="Every firm reports with the methods above, so method change requests aren't used here." />
    @else
        {{-- The team's request this quarter (items 82, N7) --}}
        @if ($request)
            <flux:card class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">Your team's request this quarter</flux:heading>
                    <flux:badge :color="match ($request->status) { 'approved' => 'emerald', 'rejected' => 'red', default => 'amber' }">
                        {{ match ($request->status) { 'approved' => 'Approved', 'rejected' => 'Declined', default => 'Waiting for your instructor' } }}
                    </flux:badge>
                </div>
                <flux:text size="sm">Filed by {{ $request->student?->user?->name }} on {{ Dates::dateTime($request->created_at) }}.</flux:text>
                <ul class="space-y-1 text-sm">
                    @foreach (AccountingChangeDisclosures::changes($request->previous_elections ?? $current, $request->proposed_elections) as $change)
                        <li>
                            {{ $change['label'] }}: {{ $change['from'] }} → <strong>{{ $change['to'] }}</strong>
                            <span class="block text-zinc-600 dark:text-zinc-400">{{ Guide::effect($change['key'], (string) $request->proposed_elections[$change['key']]) }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($request->justification)
                    <p class="text-sm"><span class="font-medium">Your reason:</span> {{ $request->justification }}</p>
                @endif
                @if ($request->status === 'approved' && $request->effective_quarter_number)
                    <p class="text-sm">Applies from Quarter {{ $request->effective_quarter_number }}, and that quarter's notes disclose the change and your reason.</p>
                @endif
                @if ($request->faculty_note)
                    <p class="text-sm"><span class="font-medium">Your instructor's note:</span> {{ $request->faculty_note }}</p>
                @endif
                @if ($request->status === 'pending')
                    <div>
                        <flux:button size="sm" wire:click="withdraw('{{ $request->id }}')" wire:confirm="Withdraw your team's request? Anyone on the team can then file a different one.">
                            Withdraw request
                        </flux:button>
                    </div>
                @elseif ($quarter->state === 'open')
                    <flux:text size="sm">Your team can file another request next quarter.</flux:text>
                @endif
            </flux:card>
        @elseif ($canFile && ! $editing)
            <flux:card class="space-y-3">
                <flux:heading size="lg" level="2">Ask to change a method</flux:heading>
                <flux:text>
                    Your team can file one request this quarter. Anyone on the team can file it, and everyone on the team sees it.
                    You'll need to explain why the new method suits your firm better.
                    @if ($quarter->decision_deadline_at?->isPast())
                        If your instructor approves before Quarter {{ $quarter->quarter_number }} closes, the change applies to this quarter's results; after that, it applies from the next quarter.
                        Quarter {{ $quarter->quarter_number }} was due to close on {{ Dates::dateTime($quarter->decision_deadline_at) }} and is still open, so it may close at any time.
                    @else
                        If your instructor approves before
                        Quarter {{ $quarter->quarter_number }} closes ({{ Dates::dateTime($quarter->decision_deadline_at) }}), the change applies to this quarter's results;
                        after that, it applies from the next quarter.
                    @endif
                </flux:text>
                <div><flux:button variant="primary" wire:click="startRequest" icon="pencil-square">Start a request</flux:button></div>
            </flux:card>
        @elseif (! $canFile && ! $request)
            <flux:callout icon="clock" heading="Requests can be filed while a quarter is open." />
        @endif

        @if ($editing)
            <form wire:submit="save" class="space-y-4">
                <flux:card class="space-y-5">
                    <flux:heading size="lg" level="2">Your team's request</flux:heading>
                    @error('election') <flux:callout variant="danger" icon="exclamation-triangle" :heading="$message" /> @enderror

                    @foreach ($fields as $field)
                        <fieldset class="space-y-2" wire:key="field-{{ $field['key'] }}">
                            <legend class="text-sm font-semibold">{{ Names::fieldLabel($field['key']) }}</legend>
                            @foreach ($field['allowed_values'] as $value)
                                <label class="flex cursor-pointer items-start gap-3 rounded-md border border-zinc-200 p-3 text-sm dark:border-white/10 has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50 dark:has-[:checked]:bg-emerald-400/10">
                                    <input type="radio" class="mt-1" wire:model="election.{{ $field['key'] }}" value="{{ $value }}">
                                    <span>
                                        <span class="font-medium">{{ Names::valueLabel($field['key'], $value) }}</span>
                                        @if (($current[$field['key']] ?? null) === $value)
                                            <span class="text-xs text-zinc-500">(current)</span>
                                        @endif
                                        <span class="block text-zinc-600 dark:text-zinc-400">{{ Guide::effect($field['key'], $value) }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </fieldset>
                    @endforeach

                    <flux:textarea wire:model="justification" rows="4" label="Why is this the better method for your firm?"
                        description="Your instructor reads this before deciding, and if the change is approved it appears in the notes to your statements." />

                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">Send to your instructor</flux:button>
                        <flux:button type="button" wire:click="cancel">Cancel</flux:button>
                    </div>
                </flux:card>
            </form>
        @endif

        @if ($earlier->isNotEmpty())
            <flux:card class="space-y-2">
                <flux:heading size="lg" level="2">Earlier requests</flux:heading>
                @foreach ($earlier as $old)
                    <p class="text-sm">
                        Quarter {{ $old->courseQuarter?->quarter_number }} ·
                        {{ $old->status === 'approved' ? 'Approved' : 'Declined' }} ·
                        @foreach (AccountingChangeDisclosures::changes($old->previous_elections ?? [], $old->proposed_elections) as $change)
                            {{ $change['label'] }} to {{ $change['to'] }}@if (! $loop->last), @endif
                        @endforeach
                        @if ($old->faculty_note) · “{{ $old->faculty_note }}” @endif
                    </p>
                @endforeach
            </flux:card>
        @endif
    @endif
</div>
