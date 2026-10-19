@php use App\Support\Dates; @endphp
<div class="flexee-page">
    <x-page-header :eyebrow="$course->name" title="Students"
        description="Everyone registered for this course, with their firm. Students who aren't in a firm yet are listed first." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    @if ($registrationUrl)
        <flux:card class="space-y-2">
            <flux:heading size="lg" level="2">Course link for students</flux:heading>
            <flux:text size="sm">Send this link to your class. Students register with it and then appear below.</flux:text>
            <div class="flex flex-wrap items-center gap-2" x-data>
                <flux:input readonly :value="$registrationUrl" class="max-w-xl" />
                <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($registrationUrl))">Copy</flux:button>
            </div>
            {{-- L22: say whether the link works right now. --}}
            @if ($course->registration_enabled)
                <p class="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400" data-test="registration-state">
                    <flux:icon.check-circle variant="mini" /> Registration is open: anyone with this link can join.
                </p>
            @else
                <p class="flex flex-wrap items-center gap-x-2 text-sm text-amber-700 dark:text-amber-400" data-test="registration-state">
                    <flux:icon.exclamation-triangle variant="mini" /> Registration is closed: students who follow this link are turned away.
                    <flux:link :href="route('faculty.courses.wizard', $course)" wire:navigate>Open it on Review setup</flux:link>
                </p>
            @endif
        </flux:card>
    @endif

    <flux:card>
        <flux:text>{{ $enrollments->count() }} {{ str('student')->plural($enrollments->count()) }} registered.</flux:text>

        @if ($enrollments->isEmpty())
            <flux:text class="mt-4">No students have registered yet.</flux:text>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                            <th class="px-3 py-2 font-medium">Student</th>
                            <th class="px-3 py-2 font-medium">Email</th>
                            <th class="px-3 py-2 font-medium">Firm</th>
                            @if ($paymentRequired)
                                <th class="px-3 py-2 font-medium">Access</th>
                            @endif
                            <th class="px-3 py-2 font-medium">Registered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach ($enrollments as $enrollment)
                            @php
                                $student = $enrollment->student;
                                $firm = $student?->firmMemberships->first()?->firm;
                            @endphp
                            <tr wire:key="enrollment-{{ $enrollment->id }}">
                                <td class="px-3 py-3 font-medium">{{ $student?->user?->name ?? '–' }}</td>
                                <td class="px-3 py-3 text-zinc-600 dark:text-zinc-300">{{ $student?->user?->email ?? '–' }}</td>
                                <td class="px-3 py-3">
                                    @if ($firm)
                                        {{ $firm->name }}
                                    @elseif ($isOwner && $firms->isNotEmpty())
                                        <form wire:submit="place('{{ $student->id }}')" class="flex items-center gap-2">
                                            <flux:select size="sm" wire:model="placement.{{ $student->id }}" placeholder="Choose a firm" class="min-w-40">
                                            <flux:select.option value="">Choose…</flux:select.option>
                                                @foreach ($firms as $option)
                                                    <flux:select.option :value="$option->id">{{ $option->name }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                            <flux:button size="sm" type="submit">Place</flux:button>
                                        </form>
                                    @else
                                        <flux:badge color="amber" size="sm">Not in a firm</flux:badge>
                                    @endif
                                </td>
                                @if ($paymentRequired)
                                    <td class="px-3 py-3">
                                        @if ($enrollment->paid_at)
                                            <flux:badge color="emerald" size="sm">Confirmed</flux:badge>
                                        @else
                                            <flux:badge color="zinc" size="sm">Waiting for payment</flux:badge>
                                        @endif
                                    </td>
                                @endif
                                <td class="px-3 py-3 text-zinc-500">{{ Dates::day($enrollment->created_at) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($paymentRequired)
                <flux:text size="sm" class="mt-3">Students pay outside Flexee. One Smarter confirms each payment, and a student's firm pages open once it's confirmed.</flux:text>
            @endif
        @endif
    </flux:card>
</div>
