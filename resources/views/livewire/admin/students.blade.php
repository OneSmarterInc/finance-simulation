<div class="flexee-page">
    <x-page-header eyebrow="Administration" title="Students"
        description="Students register from their course link. Here you can enrol or unenrol them, and confirm the paid list." />

    @if ($statusMessage)
        <flux:callout variant="success" icon="check-circle" :heading="$statusMessage" role="status" />
    @endif
    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="$errors->first()" role="alert" />
    @endif

    {{-- Paid list (item 45, N3) --}}
    <flux:card class="space-y-3">
        <flux:heading size="lg" level="2">Confirm the paid list</flux:heading>
        <flux:text size="sm">
            Paste the email addresses from the paid list for one course. Matching students are marked paid and their firm pages open.
            @unless ($paymentRequired) Paid access is switched off on this server (FLEXEE_REQUIRE_PAYMENT=false), so marks are recorded but don't gate anything. @endunless
        </flux:text>
        <form wire:submit="markPaid" class="space-y-3">
            <flux:select wire:model="paidCourseId" label="Course" placeholder="Choose a course" class="max-w-md">
                @foreach ($courses as $course)
                    <flux:select.option :value="$course->id">{{ $course->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:textarea wire:model="paidList" rows="5" label="Paid email addresses" placeholder="one@school.edu&#10;two@school.edu" />
            <flux:button type="submit" variant="primary" icon="check">Mark paid</flux:button>
        </form>

        @if ($paidReport)
            <div class="grid gap-3 text-sm md:grid-cols-2">
                @foreach ([
                    'marked' => 'Marked paid now',
                    'already' => 'Already marked',
                    'not_registered' => 'On the list but not registered for this course',
                    'not_on_list' => 'Registered but not on the list',
                ] as $key => $label)
                    <div class="rounded-md bg-zinc-50 p-3 dark:bg-white/5">
                        <p class="font-medium">{{ $label }} ({{ count($paidReport[$key]) }})</p>
                        <p class="mt-1 break-words text-zinc-600 dark:text-zinc-400">{{ implode(', ', $paidReport[$key]) ?: 'None' }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    <flux:card>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                        <th class="px-3 py-2 font-medium">Student</th>
                        <th class="px-3 py-2 font-medium">Institution</th>
                        <th class="px-3 py-2 font-medium">Courses</th>
                        <th class="px-3 py-2 font-medium">Enrol in</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                    @forelse ($students as $student)
                        <tr wire:key="student-{{ $student->id }}">
                            <td class="px-3 py-3"><span class="font-medium">{{ $student->user?->name }}</span>
                                <span class="block text-xs text-zinc-500">{{ $student->user?->email }}</span></td>
                            <td class="px-3 py-3">{{ $student->institution?->name }}</td>
                            <td class="px-3 py-3 space-y-1">
                                @forelse ($student->enrollments as $enrollment)
                                    @php($firm = $student->firmMemberships->firstWhere('course_id', $enrollment->course_id)?->firm)
                                    <div class="flex flex-wrap items-center gap-2" wire:key="enrollment-{{ $enrollment->id }}">
                                        <span>{{ $enrollment->course?->name }}{{ $firm ? ' · '.$firm->name : ' · not in a firm' }}</span>
                                        @if ($paymentRequired)
                                            <flux:badge size="sm" :color="$enrollment->paid_at ? 'emerald' : 'zinc'">{{ $enrollment->paid_at ? 'Paid' : 'Not paid' }}</flux:badge>
                                        @endif
                                        @unless ($firm)
                                            <flux:button size="xs" variant="ghost" wire:click="unenroll('{{ $enrollment->id }}')"
                                                wire:confirm="Remove {{ $student->user?->name }} from {{ $enrollment->course?->name }}?">Remove</flux:button>
                                        @endunless
                                    </div>
                                @empty
                                    <span class="text-amber-700 dark:text-amber-300">Not enrolled in any course</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-3">
                                @php($available = $courses->where('institution_id', $student->institution_id)->whereNotIn('id', $student->enrollments->pluck('course_id')))
                                @if ($available->isNotEmpty())
                                    <form wire:submit="enroll('{{ $student->id }}')" class="flex items-center gap-2">
                                        <flux:select size="sm" wire:model="enrollIn.{{ $student->id }}" placeholder="Choose a course">
                                            <flux:select.option value="">Choose…</flux:select.option>
                                            @foreach ($available as $course)
                                                <flux:select.option :value="$course->id">{{ $course->name }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                        <flux:button size="sm" type="submit">Enrol</flux:button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-zinc-500">No students yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </flux:card>
</div>
