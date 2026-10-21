@php
    // Students reach Claude as "Student 1", "Student 2"; the panel puts the names back unless asked to show what Claude saw (AC3).
    $inline = function (string $text, array $names) use ($showSeen): string {
        $html = e($text);
        if (! $showSeen) {
            // Longest label first, so "Student 12" isn't caught by "Student 1".
            foreach (collect($names)->sortBy(fn ($name, $label) => -strlen($label)) as $label => $name) {
                $html = preg_replace('/'.preg_quote(e($label), '/').'(?!\d)/', '<b>'.e($name).'</b>', $html);
            }
        }

        return $html;
    };
    // R22: bold, short lists and headings render; everything else stays text.
    $render = fn (string $text, array $names): string => \App\Support\Presentation\AnswerFormat::render($inline($text, $names));
    $asked = collect($turns)->pluck('question')->all();
    $hasNames = collect($turns)->contains(fn ($t) => ! empty($t['names']));
@endphp

<div class="flex h-full min-h-0 flex-col gap-0" data-test="ask-claude-panel">
    <div class="flex items-start justify-between gap-3 border-b border-zinc-200 pe-8 pb-3 dark:border-white/10">
        <div class="min-w-0">
            <flux:heading size="lg" level="2">Ask Claude about this course</flux:heading>
            <flux:text size="sm">Answers come from Claude, Anthropic's AI.</flux:text>
        </div>
        <flux:switch :checked="$enabled" wire:click="toggle" :label="$enabled ? 'On' : 'Off'" aria-label="Ask Claude for this course" />
    </div>

    @if ($courseOptions !== [])
        <div class="border-b border-zinc-200 py-2 dark:border-white/10">
            <flux:select wire:model.live="courseId" size="sm" label="Ask about">
                @foreach ($courseOptions as $id => $name)
                    <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    @endif

    <div class="border-b border-zinc-200 bg-zinc-50 px-1 py-2 text-xs text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
        Looking at: <span class="font-semibold text-zinc-900 dark:text-white">{{ $course->name }}</span> · {{ $context['label'] }}
        @foreach ($context['details'] ?? [] as $label => $value)
            · {{ $value }}
        @endforeach
    </div>

    @if (! $enabled)
        <flux:text class="py-6">Ask Claude is off for this course. Turn it back on with the switch above. Nothing is sent to Claude while it's off.</flux:text>
    @else
        <div class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto py-4" aria-live="polite">
            @foreach ($turns as $turn)
                <div class="flex flex-col gap-2">
                    <div class="self-end rounded-lg rounded-br-sm border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm dark:border-emerald-800 dark:bg-emerald-950">{{ $turn['question'] }}</div>
                    <div class="flex flex-col gap-2 text-sm leading-relaxed [&_p]:m-0">
                        {!! $render($turn['answer'], $turn['names'] ?? []) !!}
                    </div>
                    @foreach ($turn['views'] ?? [] as $view)
                        {{-- The table Claude asked for. Claude named the measures; every number here comes from the stored results. --}}
                        <div class="overflow-hidden rounded-md border border-zinc-200 dark:border-white/10" data-test="ask-claude-view" x-data="{ src: '' }">
                            <div class="flex items-center justify-between gap-2 border-b border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-semibold text-zinc-600 dark:border-white/10 dark:bg-white/5 dark:text-zinc-300">
                                <span>{{ $view['title'] }}</span><span class="font-normal text-zinc-500">Stored results · click a figure for its source</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs tabular-nums">
                                    <thead><tr class="border-b border-zinc-200 dark:border-white/10">
                                        <th class="px-3 py-1.5 text-left font-medium text-zinc-500"></th>
                                        @foreach ($view['columns'] as $column)
                                            <th class="whitespace-nowrap px-3 py-1.5 text-right font-medium text-zinc-500">{{ $column }}</th>
                                        @endforeach
                                    </tr></thead>
                                    <tbody>
                                        @foreach ($view['rows'] as $row)
                                            <tr class="border-b border-zinc-100 last:border-0 dark:border-white/5">
                                                <td class="whitespace-nowrap px-3 py-1.5 text-zinc-600 dark:text-zinc-300">{{ $row['label'] }}</td>
                                                @foreach ($row['cells'] as $cell)
                                                    <td class="whitespace-nowrap px-3 py-1.5 text-right">
                                                        @if ($cell['source'] ?? null)
                                                            <button type="button" data-source="{{ $cell['source'] }}" x-on:click="src = $el.dataset.source" title="{{ $cell['source'] }}"
                                                                class="border-b border-dashed border-emerald-600 font-mono text-emerald-700 dark:border-emerald-400 dark:text-emerald-400">{{ $cell['text'] }}</button>
                                                        @else
                                                            {{-- R20: nothing stored, so nothing to click. --}}
                                                            <span class="font-mono text-zinc-400 dark:text-zinc-500">{{ $cell['text'] }}</span>
                                                        @endif
                                                    </td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div x-show="src" x-cloak class="border-t border-zinc-200 px-3 py-1.5 text-[11px] text-zinc-600 dark:border-white/10 dark:text-zinc-300">
                                Source: <span x-text="src"></span>
                            </div>
                        </div>
                    @endforeach
                    <details class="rounded-md border border-zinc-200 bg-zinc-50 text-xs dark:border-white/10 dark:bg-white/5">
                        <summary class="cursor-pointer px-3 py-1.5 font-medium text-zinc-600 dark:text-zinc-300">How this answer was built</summary>
                        <ol class="list-decimal space-y-1 px-3 pb-2 ps-7 text-zinc-600 dark:text-zinc-300">
                            <li>Asked on the {{ $turn['page'] }} page. Claude read the instructors' guide.</li>
                            @foreach ($turn['lookups'] as $lookup)
                                <li><code class="font-mono">{{ $lookup['name'] }}</code> returned {{ $lookup['summary'] }}.</li>
                            @endforeach
                            @foreach ($turn['dropped'] as $drop)
                                <li>Left out: “{!! $inline($drop['sentence'], $turn['names'] ?? []) !!}” Its figure {{ implode(', ', $drop['figures']) }} isn't in anything Claude looked up, and the helper only states figures it was given.</li>
                            @endforeach
                        </ol>
                    </details>
                    <div class="text-xs font-medium text-emerald-700 dark:text-emerald-400">
                        ✓ {{ $turn['checked'] === 0 ? 'No figures in this answer' : $turn['checked'].' '.\Illuminate\Support\Str::plural('figure', $turn['checked']).' checked against what Claude looked up' }}
                    </div>
                    @if ($turn['dropped'] !== [])
                        <div class="border-s-2 border-amber-500 ps-2 text-xs text-amber-700 dark:text-amber-400">
                            {{ count($turn['dropped']) === 1 ? 'One sentence was' : count($turn['dropped']).' sentences were' }} left out because a figure in it isn't in what Claude looked up. See How this answer was built.
                        </div>
                    @endif
                </div>
            @endforeach

            <div wire:loading.flex wire:target="ask" class="items-center gap-2 text-sm text-zinc-500" role="status">
                <flux:icon.loading class="size-4" /> Claude is looking things up…
            </div>

            @if ($turns === [])
                <flux:text size="sm">Ask about this course: its setup, where each quarter stands, who has submitted, or how something works. Claude looks up what it needs and tells you what it looked at. Students are sent to Claude as numbers; you see their names here.</flux:text>
            @endif
            @if ($suggestions !== [])
                <div class="flex flex-col gap-1.5">
                    @foreach (array_diff($suggestions, $asked) as $suggestion)
                        <button type="button" wire:click="ask(@js($suggestion))" wire:loading.attr="disabled" wire:target="ask"
                            class="rounded-md border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-start text-sm hover:border-emerald-300 disabled:opacity-50 dark:border-white/10 dark:bg-white/5">{{ $suggestion }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <form wire:submit="ask" class="flex flex-col gap-2 border-t border-zinc-200 pt-3 dark:border-white/10">
            <div class="flex gap-2">
                <flux:textarea wire:model="question" rows="2" placeholder="Ask about a quarter, a firm, a request…" aria-label="Your question" class="flex-1" />
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="ask" class="self-end">Ask</flux:button>
            </div>
            @error('question')
                <flux:text size="sm" class="text-red-700 dark:text-red-400">{{ $message }}</flux:text>
            @enderror
            <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500">
                <span>{{ $left }} of {{ $allowance }} questions left this month for this course</span>
                <span class="flex gap-3">
                    @if ($hasNames)
                        <button type="button" wire:click="$toggle('showSeen')" class="underline underline-offset-2">{{ $showSeen ? 'Show names' : 'Show what Claude saw' }}</button>
                    @endif
                    @if ($turns !== [])
                        <button type="button" wire:click="clear" class="underline underline-offset-2">Start a new conversation</button>
                    @endif
                </span>
            </div>
        </form>
    @endif
</div>
