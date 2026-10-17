@php use App\Support\Product; use App\Services\Courses\CourseStatus; @endphp
<div class="flexee-page">
    <x-page-header eyebrow="Administration" :title="'Welcome, '.auth()->user()->name"
        description="Institutions, faculty, students and courses, and where every course stands." />

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Institutions', $institutionCount, 'admin.institutions'],
            ['Faculty', $facultyCount, 'admin.faculty'],
            ['Students', $studentCount, 'admin.students'],
            ['Courses', $courseCount, 'admin.courses'],
        ] as [$label, $count, $route])
            <a href="{{ route($route) }}" wire:navigate class="block rounded-xl border border-zinc-200 bg-white p-5 hover:border-emerald-600 dark:border-white/10 dark:bg-white/5">
                <span class="text-xs font-medium uppercase tracking-wide text-zinc-500">{{ $label }}</span>
                <span class="mt-2 block text-2xl font-semibold">{{ $count }}</span>
            </a>
        @endforeach
    </div>

    <flux:card class="space-y-3">
        <flux:heading size="lg" level="2">Courses</flux:heading>
        @if ($courses->isEmpty())
            <flux:text>No courses yet.</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-zinc-500 dark:border-white/10">
                            <th class="px-3 py-2 font-medium">Course</th>
                            <th class="px-3 py-2 font-medium">Product</th>
                            <th class="px-3 py-2 font-medium">Faculty</th>
                            <th class="px-3 py-2 font-medium">Now</th>
                            <th class="px-3 py-2 font-medium">Firms · students</th>
                            <th class="px-3 py-2 font-medium">Needs attention</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-white/10">
                        @foreach ($courses as $course)
                            @php
                                $status = CourseStatus::for($course);
                                $current = $status->current();
                            @endphp
                            <tr>
                                <td class="px-3 py-3"><a class="font-medium hover:underline" href="{{ route('admin.courses.prepare', $course) }}" wire:navigate>{{ $course->name }}</a>
                                    <span class="block text-xs text-zinc-500">{{ $course->institution?->name }}</span></td>
                                <td class="px-3 py-3">{{ Product::name($course) }}</td>
                                <td class="px-3 py-3">{{ $course->faculty?->user?->name }}</td>
                                <td class="px-3 py-3">
                                    @if ($course->status !== 'deployed')
                                        Setup not finished
                                    @elseif ($status->isFinished())
                                        All quarters closed
                                    @elseif ($current)
                                        Quarter {{ $current->quarter_number }} · {{ $status->stateLabel($current) }}
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ $course->firms_count }} · {{ $course->enrollments_count }}</td>
                                <td class="px-3 py-3 text-amber-700 dark:text-amber-300">{{ $course->status === 'deployed' ? count($status->needsYou()) ?: '' : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </flux:card>
</div>
