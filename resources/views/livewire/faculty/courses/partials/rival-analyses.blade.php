@php
    use App\Models\FirmRivalAnalysis;
    use App\Support\Dates;
    use App\Support\Presentation\FinancialValueFormatter;

    $money = fn ($value) => $value === null ? '–' : FinancialValueFormatter::currency((float) $value);
@endphp
{{-- U11 layer 3 (RA1 to RA3): each team's analysis of a rival it bid on. The text opens after settlement. --}}
<section class="space-y-4" data-test="rival-analyses">
    <flux:text class="max-w-3xl">
        Before a team saves a positive offer for a rival, it submits its analysis of that rival: what matters most in the statements, what the consultant's number misses, and its own figure for 51%.
        @if ($settled)
            The stage is settled, so each analysis is shown beside the outcome. You can comment, and the team sees it on its evaluation page.
        @else
            Offers are still sealed, so you see who has analysed whom. The text opens here when you settle the evaluation stage.
        @endif
    </flux:text>

    @if ($rivalRows->isEmpty())
        <flux:callout icon="scale" heading="No rival analyses yet" text="Teams write them in the evaluation stage, before they make a positive offer." />
    @elseif (! $settled)
        <flux:card class="overflow-x-auto p-0" data-test="rival-analyses-sealed">
            <table class="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs text-zinc-500 dark:border-white/10">
                        <th scope="col" class="px-4 py-2 font-medium">Team</th>
                        <th scope="col" class="px-4 py-2 font-medium">Rival</th>
                        <th scope="col" class="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rivalRows as $item)
                        @php
                            $a = $item['analysis'];
                        @endphp
                        <tr class="border-b border-zinc-100 last:border-0 dark:border-white/5" wire:key="sealed-{{ $a->id }}">
                            <td class="px-4 py-2">{{ $a->bidder?->name }}</td>
                            <td class="px-4 py-2">{{ $a->target?->name }}</td>
                            <td class="px-4 py-2">
                                @if ($a->isSubmitted())
                                    <flux:badge color="emerald" size="sm">Submitted {{ Dates::dateTime($a->submitted_at) }}</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">Draft, not submitted</flux:badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </flux:card>
    @else
        <div class="space-y-4">
            @foreach ($rivalRows as $item)
                @php
                    $a = $item['analysis'];
                    $result = $item['result'];
                    $won = $result && $result->winning_bidder_firm_id === $a->bidder_firm_id;
                @endphp
                <flux:card class="space-y-4" wire:key="rival-{{ $a->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="lg" level="3">{{ $a->bidder?->name }} on {{ $a->target?->name }}</flux:heading>
                        <div class="flex flex-wrap gap-2">
                            @if ($won)
                                <flux:badge color="sky" size="sm">Won {{ $a->target?->name }}</flux:badge>
                            @endif
                            @if ($a->isSubmitted())
                                <flux:badge color="emerald" size="sm" class="!whitespace-normal">Submitted by {{ $a->submittedBy?->user?->name ?? 'the team' }}, {{ Dates::dateTime($a->submitted_at) }}</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm">Draft only, never sent in</flux:badge>
                            @endif
                        </div>
                    </div>

                    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ([
                            "Team's figure for 51%" => [$money($a->estimated_stake_value), null],
                            "Team's offer" => [$money($item['offer']), null],
                            'Price paid' => [$result?->price_paid === null ? 'Not sold' : $money($result->price_paid), $result?->winner ? 'Won by '.$result->winner->name : null],
                            'True worth of 51%' => [$money($result?->true_stake_worth), null],
                        ] as $label => [$value, $note])
                            <div class="rounded-md bg-zinc-50 px-3 py-2 dark:bg-white/5">
                                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                                <dd class="mt-0.5 font-mono text-sm font-semibold tabular-nums">{{ $value }}</dd>
                                @if ($note)
                                    <dd class="text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <dl class="grid gap-3 text-sm md:grid-cols-3">
                        @foreach (FirmRivalAnalysis::PROMPTS as $key => $label)
                            <div class="min-w-0">
                                <dt class="font-medium">{{ $label }}</dt>
                                <dd class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ ($a->answers[$key] ?? '') !== '' ? $a->answers[$key] : 'Not written.' }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="flex flex-col gap-2 border-t border-zinc-200 pt-3 sm:flex-row sm:items-end dark:border-white/10">
                        <div class="flex-1">
                            <flux:textarea wire:model="rivalComments.{{ $a->id }}" rows="2" label="Comment to the team" placeholder="For example: you spotted the warranty reserve. Your figure was close; the offer went well past it." />
                        </div>
                        <flux:button wire:click="saveRivalComment('{{ $a->id }}')" icon="chat-bubble-left-ellipsis" class="shrink-0">Save comment</flux:button>
                    </div>
                    @error('rivalComments.'.$a->id)
                        <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </flux:card>
            @endforeach
        </div>
    @endif
</section>
