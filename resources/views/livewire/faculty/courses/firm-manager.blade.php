@php use App\Support\Dates; use App\Models\Firm; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Firms"
        :description="$started ? 'Who is in each firm and how each firm stands this quarter.' : 'Create the firms and place each registered student in one before Quarter 4 opens.'" />

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

    @if ($students->isNotEmpty())
        <flux:callout variant="warning" icon="user-plus"
            :heading="$students->count().' registered '.str('student')->plural($students->count()).' not in a firm yet'"
            :text="$students->map(fn ($s) => $s->user?->name)->filter()->join(', ').'. Place them below or from the Students page.'" />
    @endif

    {{-- Firms (items 29 to 31) --}}
    <div class="grid gap-4 lg:grid-cols-2" aria-label="Firms">
        @forelse ($firms as $firm)
            @php($team = $current && $current->state === 'open' ? $status->firmStatus($firm, $current) : null)
            <flux:card class="space-y-3" wire:key="firm-{{ $firm->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">{{ $firm->name }}</flux:heading>
                        <flux:text size="sm">Code {{ $firm->code }}</flux:text>
                    </div>
                    @if ($firm->warmup_status === Firm::WARMUP_FAILED)
                        <flux:badge color="red">Warm-up failed</flux:badge>
                    @elseif ($firm->warmup_status !== Firm::WARMUP_INITIALIZED)
                        <flux:badge color="amber">Warm-up pending</flux:badge>
                    @elseif ($team)
                        <flux:badge :color="in_array($team['state'], ['submitted', 'locked'], true) ? 'emerald' : 'amber'">Q{{ $current->quarter_number }}: {{ $team['label'] }}</flux:badge>
                    @endif
                </div>

                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Team</p>
                    @forelse ($firm->memberships as $membership)
                        <div class="flex items-center justify-between gap-2 py-1 text-sm" wire:key="member-{{ $membership->id }}">
                            <span>{{ $membership->student?->user?->name }} <span class="text-zinc-500">{{ $membership->student?->user?->email }}</span></span>
                            @unless ($status->evaluationOpen())
                                {{-- L21: removing the last member leaves a firm nobody runs. --}}
                                @php($removeWarning = $firm->memberships->count() === 1 ? $firm->name.' will have no one left. Its last decisions will carry forward every quarter, and '.$membership->student?->user?->name.' can\'t join another firm once this firm has history. Remove anyway?' : 'Remove '.$membership->student?->user?->name.' from '.$firm->name.'? They keep their account but lose access to the firm. A student can\'t move to another firm once this firm has history.')
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="removeMember('{{ $membership->student_id }}')"
                                    wire:confirm="{{ $removeWarning }}">
                                    Remove
                                </flux:button>
                            @endunless
                        </div>
                    @empty
                        <flux:text size="sm">No students yet.</flux:text>
                    @endforelse
                </div>

                <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-3 dark:border-white/10">
                    @if ($firm->warmup_status === Firm::WARMUP_INITIALIZED)
                        <flux:button size="sm" :href="route('firms.history', $firm)" icon="clock" wire:navigate>History</flux:button>
                        <flux:button size="sm" :href="route('firms.dashboard', $firm)" icon="layout-grid" wire:navigate>View as the team sees it</flux:button>
                    @endif
                </div>
            </flux:card>
        @empty
            <flux:text>No firms yet.</flux:text>
        @endforelse
    </div>

    {{-- Setup forms: at the top before play starts, folded away after (item 32) --}}
    <details @if (! $started) open @endif class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-white/10 dark:bg-white/5">
        <summary class="cursor-pointer text-base font-semibold">{{ $started ? 'Place a late student' : 'Create firms and place students' }}</summary>

        <div class="mt-4 grid gap-6 lg:grid-cols-2">
            @unless ($started)
                <form wire:submit="createFirm" class="space-y-3">
                    <flux:heading size="lg" level="2">Create a firm</flux:heading>
                    <flux:input wire:model="code" label="Short code" placeholder="ALPHA" />
                    <flux:input wire:model="name" label="Firm name" placeholder="Alpha Manufacturing" />
                    <flux:button type="submit" icon="plus" variant="primary">Create firm</flux:button>
                </form>
            @endunless

            <form wire:submit="enrollAndAssign" class="space-y-3">
                <flux:heading size="lg" level="2">Place a student</flux:heading>
                @if ($students->isEmpty())
                    <flux:text size="sm">Every registered student is in a firm. Students register from the course link on the Students page.</flux:text>
                @else
                    <flux:select wire:model="studentId" label="Student" placeholder="Choose a student">
                        @foreach ($students as $student)
                            <flux:select.option :value="$student->id">{{ $student->user?->name }} ({{ $student->user?->email }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="firmId" label="Firm" placeholder="Choose a firm">
                        @foreach ($firms as $firm)
                            <flux:select.option :value="$firm->id">{{ $firm->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" icon="user-plus" variant="primary">Place student</flux:button>
                    <flux:text size="sm">A student can't move to a different firm once their firm has history.</flux:text>
                @endif
            </form>
        </div>

        @if ($started)
            <flux:text size="sm" class="mt-4">Firms can't be added once Quarter 4 has opened, because a new firm would have no Quarter 4 to build on.</flux:text>
        @endif

        @if ($showDemoImport)
            <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-white/10">
                <flux:heading size="sm">Demo students (not shown in production)</flux:heading>
                <flux:button size="sm" class="mt-2" wire:click="importDemoStudents" icon="arrow-down-tray">Import demo students</flux:button>
            </div>
        @endif
    </details>
</div>
