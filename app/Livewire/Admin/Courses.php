<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Services\Courses\CourseRemoval;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Courses extends Component
{
    /** R12 (Vikram, 20 October 2026): an admin can remove any course, such as a test course. */
    public function remove(string $courseId, CourseRemoval $removal): void
    {
        $admin = auth()->user()?->admin;
        abort_unless($admin !== null, 403);
        $removal->removeByAdmin($admin, Course::query()->real()->findOrFail($courseId));
    }

    public function render(): View
    {
        return view('livewire.admin.courses', [
            'courses' => Course::query()->real()
                ->with(['institution', 'faculty.user', 'scenario'])
                ->withCount(['quarters', 'firms'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
