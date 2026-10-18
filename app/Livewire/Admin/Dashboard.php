<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Admin dashboard (walkthrough items 1 to 3): linked counts and where every course stands. */
#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.admin.dashboard', [
            'institutionCount' => Institution::query()->count(),
            'facultyCount' => Faculty::query()->count(),
            'studentCount' => Student::query()->where('is_practice', false)->count(),
            'courseCount' => Course::query()->real()->count(),
            'courses' => Course::query()->real()->with(['institution', 'faculty.user'])->withCount(['firms', 'enrollments'])
                ->orderByDesc('created_at')->get(),
        ]);
    }
}
