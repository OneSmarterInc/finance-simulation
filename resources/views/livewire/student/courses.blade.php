<div class="space-y-8">
    <div>
        <flux:heading size="xl">My Courses</flux:heading>

        <flux:text class="mt-2">
            Courses you are currently participating in.
        </flux:text>
    </div>

    @if ($courses->isEmpty())
        <flux:callout icon="information-circle">
            <flux:heading>No courses assigned</flux:heading>

            <flux:text>
                You are not currently assigned to any deployed simulation course.
            </flux:text>
        </flux:callout>
    @else
        <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $item)
                <flux:card class="flex h-full flex-col">
                    <div class="flex-1">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <flux:heading size="lg">
                                    {{ $item['course']->name }}
                                </flux:heading>

                                <flux:text class="mt-1">
                                    Firm: {{ $item['firm']->name }}
                                </flux:text>
                            </div>

                            <flux:badge color="green">
                                Active
                            </flux:badge>
                        </div>

                        <div class="mt-6 space-y-3">
                            @if ($item['activeQuarter'])
                                <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                    <div>
                                        <flux:text class="text-xs uppercase tracking-wide">
                                            Current Quarter
                                        </flux:text>

                                        <flux:text class="mt-1 font-medium">
                                            Q{{ $item['activeQuarter']->quarter_number }}
                                        </flux:text>
                                    </div>

                                    <flux:badge>
                                        {{ ucfirst($item['activeQuarter']->state) }}
                                    </flux:badge>
                                </div>
                            @else
                                <div class="rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                                    <flux:text class="text-sm">
                                        No quarter is currently open.
                                    </flux:text>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="mt-6">
                        <flux:button
                            href="{{ route('firms.dashboard', ['firm' => $item['firm']]) }}"
                            variant="primary"
                            class="w-full"
                            wire:navigate
                        >
                            Open Course
                        </flux:button>
                    </div>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>
