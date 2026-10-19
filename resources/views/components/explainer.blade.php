@props([
    // One of App\Support\Explainers\Explainers::all()
    'explainer',
    // Seconds each scene shows while playing.
    'seconds' => 8,
])

@php
    $scenes = $explainer['scenes'];
    $count = count($scenes);
    $key = $explainer['key'];
    $tone = fn (string $t) => match ($t) {
        // A change is marked, not judged: a cost going up isn't "good" or "bad" here.
        'up', 'down' => 'bg-amber-100 text-amber-900 dark:bg-amber-400/15 dark:text-amber-200',
        default => 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-300',
    };
    // Bars are drawn against the largest value in the scene.
    $width = function (array $bars, array $bar): float {
        $max = max(array_map(fn ($b) => (float) $b['value'], $bars)) ?: 1;

        return round(max(2, 100 * (float) $bar['value'] / $max), 1);
    };
@endphp

<section
    x-data="{
        scene: 0, count: {{ $count }}, playing: false, shown: true, timer: null, finished: false,
        reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
        go(i) {
            if (i < 0 || i >= this.count) return;
            this.shown = false;
            this.scene = i;
            this.$nextTick(() => requestAnimationFrame(() => { this.shown = true; }));
            if (i === this.count - 1 && ! this.finished) {
                this.finished = true;
                this.$dispatch('explainer-finished', { key: @js($key) });
            }
        },
        play() {
            this.go(this.scene === this.count - 1 ? 0 : this.scene);
            this.playing = true;
            clearInterval(this.timer);
            this.timer = setInterval(() => {
                if (this.scene >= this.count - 1) { this.pause(); return; }
                this.go(this.scene + 1);
            }, {{ (int) $seconds * 1000 }});
        },
        pause() { this.playing = false; clearInterval(this.timer); },
        step(i) { this.pause(); this.go(i); },
    }"
    x-on:keydown.right.prevent="step(scene + 1)" x-on:keydown.left.prevent="step(scene - 1)"
    tabindex="-1" id="{{ $key }}" data-test="explainer-{{ $key }}" aria-roledescription="explainer" aria-label="{{ $explainer['title'] }}"
    {{ $attributes->merge(['class' => 'scroll-mt-24 rounded-xl border border-zinc-200 bg-white p-4 sm:p-5 dark:border-white/10 dark:bg-zinc-900']) }}>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-balance text-zinc-900 dark:text-white">{{ $explainer['title'] }}</h3>
            <p class="mt-0.5 max-w-prose text-sm text-zinc-600 dark:text-zinc-400">{{ $explainer['summary'] }}</p>
        </div>
        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase tabular-nums dark:text-zinc-400" aria-live="polite">
            Step <span x-text="scene + 1">1</span> of {{ $count }}
        </p>
    </div>

    {{-- The scenes --}}
    <div class="mt-4 rounded-lg bg-zinc-50 p-3 sm:p-4 dark:bg-white/5">
        @foreach ($scenes as $i => $s)
            <div x-show="scene === {{ $i }}" @if ($i > 0) x-cloak @endif data-scene="{{ $i + 1 }}" class="min-h-[15rem] space-y-3">
                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-300">{{ $s['title'] }}</p>

                @switch($s['visual'])
                    @case('history')
                    @case('choice')
                    @case('close')
                        <div class="space-y-2.5" role="img" aria-label="{{ $s['measure'] }}: {{ collect($s['bars'])->map(fn ($b) => $b['label'].' '.$b['text'])->join(', ') }}">
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $s['measure'] }}</p>
                            @foreach ($s['bars'] as $j => $bar)
                                <div class="grid grid-cols-[minmax(0,7rem)_minmax(0,1fr)] items-center gap-3 sm:grid-cols-[minmax(0,11rem)_minmax(0,1fr)]">
                                    <span class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $bar['label'] }}</span>
                                    <div class="min-w-0">
                                        <div class="h-7 overflow-hidden rounded bg-zinc-200/70 dark:bg-white/10">
                                            <div class="flex h-full items-center rounded bg-emerald-600 px-2 transition-[width] duration-1000 ease-out motion-reduce:transition-none dark:bg-emerald-500"
                                                x-bind:style="'width: ' + ((shown || reduce) ? '{{ $width($s['bars'], $bar) }}%' : '0%') + '; transition-delay: {{ $j * 250 }}ms'"
                                                style="width: {{ $width($s['bars'], $bar) }}%"></div>
                                        </div>
                                        <p class="mt-0.5 text-xs text-zinc-600 tabular-nums dark:text-zinc-400"><span class="font-semibold text-zinc-900 dark:text-white">{{ $bar['text'] }}</span>@isset($bar['note']) · {{ $bar['note'] }}@endisset</p>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('statements')
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($s['cards'] as $j => $card)
                                <div class="rounded-lg border border-zinc-200 bg-white p-3 transition duration-500 ease-out motion-reduce:transition-none dark:border-white/10 dark:bg-zinc-900"
                                    x-bind:class="(shown || reduce) ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'" style="transition-delay: {{ $j * 200 }}ms">
                                    <p class="text-sm font-semibold">{{ $card['name'] }}</p>
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $card['what'] }}</p>
                                    <p class="mt-2 text-xs text-zinc-600 dark:text-zinc-400">{{ $card['line'] }}</p>
                                    <p class="text-sm font-semibold tabular-nums">{{ $card['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('forecast')
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[20rem] text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                        <th class="py-1.5 text-left font-medium">Forecast</th>
                                        <th class="py-1.5 text-right font-medium">Last quarter's orders</th>
                                        <th class="py-1.5 text-right font-medium">Quarter 4 forecast</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($s['rows'] as $j => $row)
                                        <tr class="border-b border-zinc-200 transition duration-500 ease-out motion-reduce:transition-none dark:border-white/10"
                                            x-bind:class="(shown || reduce) ? 'opacity-100' : 'opacity-0'" style="transition-delay: {{ $j * 200 }}ms">
                                            <td class="py-1.5">{{ $row['label'] }}</td>
                                            <td class="py-1.5 text-right text-zinc-500 tabular-nums dark:text-zinc-400">{{ $row['last'] }}</td>
                                            <td class="py-1.5 text-right font-semibold tabular-nums">
                                                <span class="rounded bg-white px-1.5 py-0.5 ring-1 ring-zinc-300 dark:bg-zinc-900 dark:ring-white/20">{{ $row['value'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Each box starts at last quarter's value, with last quarter's figure shown underneath.</p>
                        @break

                    @case('deadline')
                        <ol class="grid gap-3 sm:grid-cols-3">
                            @foreach ($s['steps'] as $j => $label)
                                <li class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white p-3 transition duration-500 ease-out motion-reduce:transition-none dark:border-white/10 dark:bg-zinc-900"
                                    x-bind:class="(shown || reduce) ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-2'" style="transition-delay: {{ $j * 450 }}ms">
                                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $j === 2 ? 'bg-amber-500 text-white' : 'bg-emerald-700 text-white' }} text-xs font-semibold">{{ $j + 1 }}</span>
                                    <span class="text-sm font-medium">{{ $label }}</span>
                                </li>
                            @endforeach
                        </ol>
                        @break

                    @case('compare')
                        {{-- On a phone, each line stacks so both columns stay in view. --}}
                        <dl class="space-y-2 sm:hidden">
                            @foreach ($s['rows'] as $j => $row)
                                <div class="rounded-lg border border-zinc-200 bg-white p-2.5 transition duration-500 ease-out motion-reduce:transition-none dark:border-white/10 dark:bg-zinc-900"
                                    x-bind:class="(shown || reduce) ? 'opacity-100' : 'opacity-0'" style="transition-delay: {{ $j * 300 }}ms">
                                    <div class="flex items-center justify-between gap-2">
                                        <dt class="text-sm font-medium">{{ $row['label'] }}</dt>
                                        <span class="rounded px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap tabular-nums {{ $tone($row['tone']) }}">{{ $row['change'] }}</span>
                                    </div>
                                    <dd class="mt-1 grid grid-cols-2 gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                                        <span>{{ $s['columns'][0] }}<span class="block text-sm text-zinc-900 tabular-nums dark:text-white">{{ $row['a'] }}</span></span>
                                        <span>{{ $s['columns'][1] }}<span class="block text-sm text-zinc-900 tabular-nums dark:text-white">{{ $row['b'] }}</span></span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="w-full min-w-[34rem] text-sm">
                                <thead>
                                    <tr class="border-b border-zinc-300 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
                                        <th class="py-1.5 text-left font-medium"><span class="sr-only">Line</span></th>
                                        <th class="py-1.5 pl-3 text-right font-medium">{{ $s['columns'][0] }}</th>
                                        <th class="py-1.5 pl-3 text-right font-medium">{{ $s['columns'][1] }}</th>
                                        <th class="py-1.5 text-right font-medium">Change</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($s['rows'] as $j => $row)
                                        <tr class="border-b border-zinc-200 transition duration-500 ease-out motion-reduce:transition-none dark:border-white/10"
                                            x-bind:class="(shown || reduce) ? 'opacity-100' : 'opacity-0'" style="transition-delay: {{ $j * 300 }}ms">
                                            <td class="py-1.5 pr-3">{{ $row['label'] }}</td>
                                            <td class="py-1.5 pl-3 text-right whitespace-nowrap tabular-nums">{{ $row['a'] }}</td>
                                            <td class="py-1.5 pl-3 text-right whitespace-nowrap tabular-nums">{{ $row['b'] }}</td>
                                            <td class="py-1.5 pl-2 text-right">
                                                <span class="inline-block rounded px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap tabular-nums {{ $tone($row['tone']) }}">{{ $row['change'] }}</span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @break

                    @case('note')
                        <div class="rounded-lg border-l-4 border-emerald-700 bg-white p-3 transition duration-700 ease-out motion-reduce:transition-none dark:border-emerald-400 dark:bg-zinc-900"
                            x-bind:class="(shown || reduce) ? 'opacity-100' : 'opacity-0'">
                            <p class="text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-400">Notes · Accounting changes</p>
                            <p class="mt-1 text-sm">{{ $s['note'] }}</p>
                        </div>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">Double-declining balance: {{ $s['method'] }}</p>
                        @break

                    @case('summary')
                        <div class="grid gap-2 sm:grid-cols-3">
                            @foreach ($s['chips'] as $j => $chip)
                                <div class="rounded-lg p-3 text-center transition duration-500 ease-out motion-reduce:transition-none {{ $tone($chip['tone']) }}"
                                    x-bind:class="(shown || reduce) ? 'opacity-100 scale-100' : 'opacity-0 scale-95'" style="transition-delay: {{ $j * 300 }}ms">
                                    <p class="text-xs font-medium">{{ $chip['label'] }}</p>
                                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ $chip['value'] }}</p>
                                </div>
                            @endforeach
                        </div>
                        @break
                @endswitch

                <p class="max-w-prose pt-1 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $s['caption'] }}</p>
            </div>
        @endforeach
    </div>

    {{-- The controls --}}
    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <flux:button size="sm" icon="chevron-left" x-on:click="step(scene - 1)" x-bind:disabled="scene === 0" data-explainer-back>Back</flux:button>
            <flux:button size="sm" variant="primary" icon="play" x-show="! playing" x-on:click="play()" data-explainer-play>Play</flux:button>
            <flux:button size="sm" variant="primary" icon="pause" x-show="playing" x-cloak x-on:click="pause()" data-explainer-pause>Pause</flux:button>
            <flux:button size="sm" icon-trailing="chevron-right" x-on:click="step(scene + 1)" x-bind:disabled="scene === count - 1" data-explainer-next>Next</flux:button>
        </div>
        <div class="flex items-center gap-1.5" role="group" aria-label="Go to a step">
            @foreach ($scenes as $i => $s)
                <button type="button" x-on:click="step({{ $i }})" aria-label="Step {{ $i + 1 }}: {{ $s['title'] }}"
                    x-bind:aria-current="scene === {{ $i }} ? 'step' : null"
                    x-bind:class="scene === {{ $i }} ? 'w-5 bg-emerald-700 dark:bg-emerald-400' : 'w-2 bg-zinc-300 hover:bg-zinc-400 dark:bg-white/20'"
                    class="h-2 rounded-full transition-all motion-reduce:transition-none"></button>
            @endforeach
        </div>
    </div>
    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ \App\Support\Explainers\Explainers::SOURCE }}</p>
</section>
