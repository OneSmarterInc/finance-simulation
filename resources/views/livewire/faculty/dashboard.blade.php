@php use App\Support\Dates; use App\Support\Product; use App\Services\Courses\CourseStatus; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="Product::NAME" :title="'Welcome, '.auth()->user()->name"
        description="Your courses, where each one stands, and what needs you.">
        @unless ($courses->isEmpty())
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" :href="route('faculty.courses.create')" wire:navigate data-test="new-course">New course</flux:button>
            </x-slot:actions>
        @endunless
    </x-page-header>

    @if ($courses->isEmpty())
        <flux:card class="space-y-4">
            <flux:heading size="lg">No courses yet</flux:heading>
            <flux:text class="max-w-2xl">Set up your first course: its name, schedule, the decision areas that open each quarter and the starting accounting methods. You can rehearse it with a practice run before any student joins.</flux:text>
            <div>
                <flux:button variant="primary" icon="plus" :href="route('faculty.courses.create')" wire:navigate data-test="new-course">New course</flux:button>
            </div>
        </flux:card>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($courses as $course)
                @php
                    $status = CourseStatus::for($course);
                    $current = $status->current();
                    $needs = $course->status === 'deployed' ? $status->needsYou() : [];
                @endphp
                <flux:card class="!p-5 space-y-4" wire:key="course-{{ $course->id }}">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <flux:heading size="lg">{{ $course->name }}</flux:heading>
                            <flux:text class="mt-1">{{ Product::fullName($course) }}</flux:text>
                        </div>
                        @if ($course->status !== 'deployed')
                            <flux:badge color="zinc">Setup not finished</flux:badge>
                        @endif
                    </div>

                    {{-- Where the course stands (item 15) --}}
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-white/10 dark:bg-white/5">
                        @if ($course->status !== 'deployed')
                            Finish the course setup to deploy it.
                        @elseif ($status->isFinished())
                            Every quarter has closed.
                        @elseif ($current)
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">Quarter {{ $current->quarter_number }}</span>
                                <flux:badge size="sm" :color="$status->stateColor($current)">{{ $status->stateLabel($current) }}</flux:badge>
                                @if ($status->isEvaluationQuarter($current))
                                    <flux:badge size="sm" color="violet">Evaluation stage</flux:badge>
                                @endif
                            </div>
                            <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                                @if ($current->state === 'open' && $status->isOverdue($current))
                                    The deadline ({{ Dates::dateTime($current->decision_deadline_at) }}) has passed and the quarter hasn't closed.
                                    {{ $status->submittedCount($current) }} of {{ $status->firms->count() }} firms submitted.
                                @elseif ($current->state === 'open')
                                    Decisions due {{ Dates::dateTime($current->decision_deadline_at) }} ({{ Dates::remaining($current->decision_deadline_at) }}).
                                    {{ $status->submittedCount($current) }} of {{ $status->firms->count() }} firms submitted.
                                @elseif ($current->state === 'processing')
                                    Results are being calculated.
                                @else
                                    {{ $status->opensWhen($current) }}
                                @endif
                            </p>
                        @endif
                        <p class="mt-1 text-zinc-500">{{ $status->firms->count() }} {{ str('firm')->plural($status->firms->count()) }}.</p>
                    </div>

                    {{-- Needs you (item 16) --}}
                    @if ($needs !== [])
                        <div class="space-y-1">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Needs you</p>
                            @foreach ($needs as $item)
                                <a href="{{ $item['route'] }}" wire:navigate class="block text-sm text-zinc-800 underline-offset-2 hover:underline dark:text-zinc-200">{{ $item['text'] }}</a>
                            @endforeach
                        </div>
                    @endif

                    <div class="flex flex-wrap gap-2 border-t border-zinc-200 pt-4 dark:border-white/10">
                        @if ($course->status === 'deployed')
                            <flux:button :href="route('faculty.courses.quarters', $course)" icon="arrow-right" variant="primary" wire:navigate>Open course</flux:button>
                            <flux:button :href="route('faculty.courses.quarter-results', $course)" icon="chart-bar" wire:navigate>Quarter results</flux:button>
                        @else
                            <flux:button :href="route('faculty.courses.wizard', $course)" icon="pencil-square" variant="primary" wire:navigate>Continue setup</flux:button>
                            @if ($course->faculty_id === auth()->user()->faculty?->id)
                                <flux:button variant="ghost" icon="trash" wire:click="deleteDraft('{{ $course->id }}')"
                                    wire:confirm="Delete the draft {{ $course->name }}? It was never deployed, so it has no students or results. One Smarter can restore it if you need it back.">Delete draft</flux:button>
                            @endif
                        @endif
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
