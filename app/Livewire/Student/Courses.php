<?php

namespace App\Livewire\Student;

use App\Models\FirmMembership;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.student')]
class Courses extends Component
{
    public function render(): View
    {
        $user = auth()->user();

        abort_unless($user !== null && $user->student !== null, 403);

        $memberships = FirmMembership::query()
            ->where('student_id', $user->student->id)
            ->with([
                'firm.course',
                'student',
            ])
            ->whereHas('firm.course', function ($query) {
                $query->where('status', 'deployed');
            })
            ->orderByDesc('assigned_at')
            ->get();

        $courses = $memberships->map(function (FirmMembership $membership) {
            $firm = $membership->firm;
            $course = $firm?->course;

            $status = \App\Services\Courses\CourseStatus::for($course);
            $activeQuarter = $status->active();

            return [
                'firm' => $firm,
                'course' => $course,
                'status' => $status,
                'activeQuarter' => $activeQuarter,
                'teamStatus' => $activeQuarter ? $status->firmStatus($firm, $activeQuarter) : null,
                'teammates' => $firm->memberships()->with('student.user')->get()
                    ->reject(fn ($m) => $m->student_id === $membership->student_id)
                    ->map(fn ($m) => $m->student?->user?->name)->filter()->values(),
                'paid' => \App\Services\Students\StudentAccess::isPaid($membership->student, $course),
                'assignedAt' => $membership->assigned_at,
            ];
        });

        return view('livewire.student.courses', [
            'courses' => $courses,
        ]);
    }
}
