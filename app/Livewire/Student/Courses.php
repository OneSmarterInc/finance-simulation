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
            ])
            ->whereHas('firm.course', function ($query) {
                $query->where('status', 'deployed');
            })
            ->orderByDesc('assigned_at')
            ->get();

        $courses = $memberships->map(function (FirmMembership $membership) {
            $firm = $membership->firm;
            $course = $firm?->course;

            $activeQuarter = $course?->quarters()
                ->whereIn('state', ['open', 'processing'])
                ->orderBy('quarter_number')
                ->first();

            return [
                'firm' => $firm,
                'course' => $course,
                'activeQuarter' => $activeQuarter,
                'assignedAt' => $membership->assigned_at,
            ];
        });

        return view('livewire.student.courses', [
            'courses' => $courses,
        ]);
    }
}
