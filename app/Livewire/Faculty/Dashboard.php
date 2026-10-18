<?php

namespace App\Livewire\Faculty;

use App\Models\Course;
use App\Models\Faculty;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render(): View
    {
        $faculty = $this->faculty();

        $courses = Course::query()->real()
            ->where(function ($query) use ($faculty): void {
                $query
                    ->where('faculty_id', $faculty->id)
                    ->orWhereHas('facultyRoles', function ($roleQuery) use ($faculty): void {
                        $roleQuery
                            ->where('faculty_id', $faculty->id)
                            ->where('role', 'co_faculty');
                    });
            })
            ->withCount(['quarters', 'firms'])
            ->with('scenario')
            ->orderByDesc('created_at')
            ->get();

        return view('livewire.faculty.dashboard', [
            'courses' => $courses,
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }
}
