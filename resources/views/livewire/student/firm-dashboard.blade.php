@php use App\Support\Dates; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$firm->course->name.' · '.\App\Support\Product::name($firm->course)" :title="$firm->name"
        :description="$members->isNotEmpty() ? 'Team: '.$members->join(', ', ' and ') : null">
        <x-slot:actions>
            <flux:button :href="route('firms.history', $firm)" icon="clock" wire:navigate>Full history</flux:button>
        </x-slot:actions>
    </x-page-header>

    {{-- What to do now (item 70) --}}
    @if ($active && $status->isOverdue($active))
        {{-- L12: the deadline has passed and the quarter hasn't closed yet. --}}
        <flux:callout variant="warning" icon="clock" :heading="'Quarter '.$active->quarter_number.' is waiting to close.'" data-test="deadline-passed">
            <flux:callout.text>
                {{ $status->overdueText($active) }}
                @if (in_array($teamStatus['state'], ['submitted', 'locked'], true))
                    Your team submitted, so its decisions are the ones that will be used.
                @endif
            </flux:callout.text>
            @unless ($facultyView)
                <x-slot name="actions">
                    <flux:button :href="route('student.decisions.workspace', ['firm' => $firm, 'quarter' => $active])" icon="eye" wire:navigate>
                        See your decisions
                    </flux:button>
                </x-slot>
            @endunless
        </flux:callout>
    @elseif ($active && $active->state === \App\Models\CourseQuarter::OPEN)
        <flux:callout :variant="in_array($teamStatus['state'], ['submitted', 'locked'], true) ? 'success' : 'warning'" icon="pencil-square"
            :heading="'Quarter '.$active->quarter_number.' is open. Decisions are due '.Dates::dateTime($active->decision_deadline_at).'.'">
            <flux:callout.text>
                @switch($teamStatus['state'])
                    @case('submitted')
                        {{ $teamStatus['label'] }}. You can still change it until the deadline.
                        @break
                    @case('draft')
                        Your team has a draft saved but hasn't submitted yet. If nothing is submitted by the deadline, last quarter's decisions are used again.
                        @break
                    @default
                        Your team hasn't submitted yet. If nothing is submitted by the deadline, last quarter's decisions are used again.
                @endswitch
                @if ($toExplain)
                    <span class="mt-1 block font-medium" data-test="explain-first">First, explain Quarter {{ $toExplain->quarter_number }}: your team submits its explanation of that quarter's statements, then its decisions. Both are on the decisions page.</span>
                @endif
            </flux:callout.text>
            @unless ($facultyView)
                <x-slot name="actions">
                    <flux:button :href="route('student.decisions.workspace', ['firm' => $firm, 'quarter' => $active])" variant="primary" icon="arrow-right" wire:navigate>
                        Go to decisions
                    </flux:button>
                </x-slot>
            @endunless
        </flux:callout>
    @elseif ($active && $active->state === \App\Models\CourseQuarter::PROCESSING)
        <flux:callout icon="clock" :heading="'Quarter '.$active->quarter_number.' has closed and results are being calculated.'"
            text="The next quarter opens as soon as every firm's results are ready." />
    @elseif ($status->isFinished())
        <flux:callout icon="check-circle" heading="Every quarter of the course has closed." text="Your firm's full record is on the History page." />
    @elseif ($next = $status->next())
        <flux:callout icon="calendar-days" :heading="'Quarter '.$next->quarter_number.' is not open yet.'" :text="$status->opensWhen($next)" />
    @endif

    @if ($results->isEmpty())
        <flux:callout icon="chart-bar" heading="No results yet" text="Your firm's warm-up quarters appear here as soon as the firm is set up." />
    @else
        <flux:card class="space-y-4">
            <div>
                <flux:heading size="lg" level="2">Where your firm stands</flux:heading>
                <flux:text class="mt-1">The latest quarter beside the one before it. History has every quarter.</flux:text>
            </div>
            <x-firm-report :columns="$columns" :decisions="$decisionColumns" :disclosures="$disclosureColumns" :parameters="$parameters" />
        </flux:card>
    @endif
</div>
