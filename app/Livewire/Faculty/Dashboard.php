<?php

namespace App\Livewire\Faculty;

use App\Models\Course;
use App\Models\Faculty;
use App\Support\Navigation\Home;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    /** Live, 19 October 2026: "Go to your courses" sent students and admins here, to a bare 403. They go to their own start page. */
    public function mount()
    {
        $user = auth()->user();
        if ($user?->faculty) {
            return null;
        }
        $home = Home::routeFor($user);
        if ($home === null) {
            return Home::signOut();
        }

        return $this->redirectRoute($home, navigate: true);
    }

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
