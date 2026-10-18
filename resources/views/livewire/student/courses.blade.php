@php use App\Support\Dates; use App\Support\Product; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="Product::NAME" title="My courses" />

    @if ($courses->isEmpty())
        <flux:callout icon="information-circle" heading="You're not in a firm yet"
            text="Once your instructor places you in a firm, your course appears here." />
    @else
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $item)
                <flux:card class="flex h-full flex-col">
                    <div class="flex-1 space-y-4">
                        <div>
                            <flux:heading size="lg">{{ $item['course']->name }}</flux:heading>
                            <flux:text class="mt-1">{{ Product::fullName($item['course']) }}</flux:text>
                            <flux:text class="mt-1">
                                {{ $item['firm']->name }}{{ $item['teammates']->isNotEmpty() ? ', with '.$item['teammates']->join(', ', ' and ') : '' }}
                            </flux:text>
                        </div>

                        <div class="rounded-lg border border-zinc-200 px-4 py-3 text-sm dark:border-zinc-700">
                            @if (! $item['paid'])
                                Access opens once your registration is confirmed.
                            @elseif ($item['activeQuarter'] && $item['activeQuarter']->state === 'open')
                                @if ($item['status']->isOverdue($item['activeQuarter']))
                                    <p class="font-medium">Quarter {{ $item['activeQuarter']->quarter_number }}'s deadline ({{ Dates::dateTime($item['activeQuarter']->decision_deadline_at) }}) has passed. It's waiting to close.</p>
                                @else
                                    <p class="font-medium">Quarter {{ $item['activeQuarter']->quarter_number }} decisions due {{ Dates::dateTime($item['activeQuarter']->decision_deadline_at) }}</p>
                                @endif
                                <p @class(['mt-1', 'text-emerald-700 dark:text-emerald-300' => in_array($item['teamStatus']['state'], ['submitted', 'locked'], true), 'text-amber-700 dark:text-amber-300' => ! in_array($item['teamStatus']['state'], ['submitted', 'locked'], true)])>
                                    {{ $item['teamStatus']['label'] }}
                                </p>
                            @elseif ($item['activeQuarter'])
                                Quarter {{ $item['activeQuarter']->quarter_number }} has closed. Results are being calculated.
                            @elseif ($item['status']->isFinished())
                                Every quarter has closed.
                            @elseif ($next = $item['status']->next())
                                Quarter {{ $next->quarter_number }}: {{ lcfirst($item['status']->opensWhen($next)) }}
                            @endif
                        </div>
                    </div>

                    <div class="mt-6">
                        <flux:button href="{{ route('firms.dashboard', ['firm' => $item['firm']]) }}" variant="primary" class="w-full" wire:navigate>
                            Open {{ $item['firm']->name }}
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
