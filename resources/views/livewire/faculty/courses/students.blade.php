<div class="flexee-page">

    <x-page-header eyebrow="FLEXEE WORKSPACE" :title="$course->name . ' — Students / Participants'"
        description="Students registered for this simulation course." />

    <div class="mb-5">
        <flux:button :href="route('dashboard')" icon="arrow-left" variant="ghost">
            Back to Courses
        </flux:button>
    </div>

    <flux:card>

        <div class="flex items-center justify-between gap-4">
            <div>
                <flux:heading size="lg">
                    Students / Participants
                </flux:heading>

                <flux:text class="mt-1">
                    {{ $participants->count() }}
                    {{ str('participant')->plural($participants->count()) }}
                    registered for this simulation.
                </flux:text>
            </div>

            <flux:badge color="zinc">
                {{ $participants->count() }}
            </flux:badge>
        </div>

        @if ($participants->isEmpty())

            <div class="mt-6 rounded-lg border border-dashed border-zinc-300 p-8 text-center dark:border-white/10">
                <flux:heading size="sm">
                    No participants yet
                </flux:heading>

                <flux:text class="mt-1">
                    Students who register for this simulation will appear here.
                </flux:text>
            </div>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-white/10">

                            <th class="px-3 py-3">
                                <button type="button" wire:click="sort('name')"
                                    class="inline-flex items-center gap-1 font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                                    Participant

                                    @if ($sortBy === 'name')
                                        <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </button>
                            </th>

                            <th class="px-3 py-3 font-medium text-zinc-500">
                                Email
                            </th>

                            <th class="px-3 py-3">
                                <button type="button" wire:click="sort('firm')"
                                    class="inline-flex items-center gap-1 font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-white">
                                    Firm

                                    @if ($sortBy === 'firm')
                                        <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                    @endif
                                </button>
                            </th>

                            <th class="px-3 py-3">

                                <button type="button" wire:click="sort('registered')"
                                    class="inline-flex items-center gap-1 font-medium text-zinc-500 hover:text-zinc-900 dark:hover:text-white">

                                    Registered

                                    @if ($sortBy === 'registered')
                                        <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                    @endif

                                </button>

                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">

                        @foreach ($participants as $enrollment)
                            @php
                                $student = $enrollment->student;
                                $membership = $student?->firmMemberships->first();
                            @endphp

                            <tr>
                                <td class="px-3 py-4 font-medium text-zinc-900 dark:text-white">
                                    {{ $student?->user?->name ?? '—' }}
                                </td>

                                <td class="px-3 py-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $student?->user?->email ?? '—' }}
                                </td>

                                <td class="px-3 py-4">
                                    @if ($membership?->firm)
                                        <flux:badge color="emerald">
                                            {{ $membership->firm->name }}
                                        </flux:badge>
                                    @else
                                        <flux:badge color="zinc">
                                            Not assigned
                                        </flux:badge>
                                    @endif
                                </td>

                                <td class="px-3 py-4 text-zinc-500">
                                    {{ $enrollment->created_at?->format('d M Y') ?? '—' }}
                                </td>
                            </tr>
                        @endforeach

                    </tbody>
                </table>
            </div>

        @endif

    </flux:card>

</div>
