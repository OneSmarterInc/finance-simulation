@php
    use App\Support\Dates;
@endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Team explanations"
        description="Before each quarter's decisions, every team explains what its statements showed the quarter before. Read them here, send a comment the team sees, and download them all for grading.">
        @if ($quarters->isNotEmpty())
            <x-slot:actions>
                <flux:button icon="arrow-down-tray" :href="route('faculty.courses.explanations.download', $course)" data-test="explanations-download">Download (.xlsx)</flux:button>
            </x-slot:actions>
        @endif
    </x-page-header>

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif

    <flux:card class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="explanations-requirement">
        <div class="space-y-1">
            <flux:heading level="2">
                {{ $course->interpretations_required ? 'Teams explain each quarter before they decide' : 'Explanations are switched off for this course' }}
            </flux:heading>
            <flux:text size="sm" class="max-w-2xl">
                @if ($course->interpretations_required)
                    A team can save a draft of its decisions at any time, but it can submit them only after it submits its explanation of the quarter before.
                    @if ($course->cf_plus_enabled)
                        In the evaluation stage, a team also submits its analysis of a rival before it can make a positive offer for it.
                    @endif
                @else
                    Teams submit decisions without writing an explanation. Switch it on to ask for one before each quarter's decisions.
                @endif
            </flux:text>
        </div>
        <flux:button wire:click="toggleRequired" class="shrink-0" :variant="$course->interpretations_required ? 'outline' : 'primary'">
            {{ $course->interpretations_required ? 'Switch off' : 'Switch on' }}
        </flux:button>
    </flux:card>

    @if ($quarters->isEmpty() && ! $course->cf_plus_enabled)
        <flux:callout icon="pencil-square" heading="No explanations yet" text="Teams first explain Quarter 3, the last warm-up quarter, before they submit Quarter 4's decisions." />
    @else
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Explanations of:</span>
            @foreach ($quarters as $number)
                <flux:button size="sm" type="button" wire:click="showQuarter({{ $number }})" :variant="$show === 'quarters' && $selectedQuarter === $number ? 'primary' : 'outline'">Q{{ $number }}</flux:button>
            @endforeach
            @if ($course->cf_plus_enabled)
                <span class="mx-1 h-5 w-px bg-zinc-300 dark:bg-white/20" aria-hidden="true"></span>
                <flux:button size="sm" type="button" wire:click="showRivals" icon="scale" :variant="$show === 'rivals' ? 'primary' : 'outline'" data-test="show-rival-analyses">Rival analyses</flux:button>
            @endif
        </div>

        @if ($show === 'rivals')
            @include('livewire.faculty.courses.partials.rival-analyses')
        @elseif ($quarter)
            <flux:text>
                {{ $submitted }} of {{ $firms->count() }} {{ str('firm')->plural($firms->count()) }} submitted an explanation of Quarter {{ $quarter->quarter_number }}.
                @if ($dueBy)
                    {{ $dueBy->isPast() ? 'It was due by' : 'It\'s due by' }} {{ Dates::dateTime($dueBy) }}, with Quarter {{ $quarter->quarter_number + 1 }}'s decisions.
                @endif
            </flux:text>

            <div class="space-y-4">
                @foreach ($firms as $firm)
                    @php
                        $row = $rows->get($firm->id);
                    @endphp
                    <flux:card class="space-y-3" wire:key="explanation-{{ $firm->id }}-{{ $selectedQuarter }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:heading size="lg" level="3">{{ $firm->name }}</flux:heading>
                            @if ($row?->isSubmitted())
                                <flux:badge color="emerald">Submitted by {{ $row->submittedBy?->user?->name ?? 'the team' }}, {{ Dates::dateTime($row->submitted_at) }}</flux:badge>
                            @elseif ($row)
                                <flux:badge color="amber">Draft, not submitted</flux:badge>
                            @else
                                <flux:badge color="zinc">Not started</flux:badge>
                            @endif
                        </div>
                        @if ($row)
                            <dl class="grid gap-3 text-sm md:grid-cols-3">
                                @foreach (['changed' => 'What changed?', 'why' => 'Why did it change?', 'notes' => 'What do the notes show?'] as $key => $label)
                                    <div class="min-w-0">
                                        <dt class="font-medium">{{ $label }}</dt>
                                        <dd class="mt-1 whitespace-pre-line text-zinc-700 dark:text-zinc-300">{{ ($row->answers[$key] ?? '') !== '' ? $row->answers[$key] : 'Not written.' }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                            <div class="flex flex-col gap-2 border-t border-zinc-200 pt-3 sm:flex-row sm:items-end dark:border-white/10">
                                <div class="flex-1">
                                    <flux:textarea wire:model="comments.{{ $firm->id }}" rows="2" label="Comment to the team" placeholder="For example: good use of the inventory note. Next time, say how much of the cash went into stock." />
                                </div>
                                <flux:button wire:click="saveComment('{{ $firm->id }}')" icon="chat-bubble-left-ellipsis" class="shrink-0">Save comment</flux:button>
                            </div>
                            @error('comments.'.$firm->id)
                                <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        @else
                            <flux:text size="sm">Nothing written yet.</flux:text>
                        @endif
                    </flux:card>
                @endforeach
            </div>
        @endif
    @endif
</div>
