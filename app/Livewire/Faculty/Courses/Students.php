<?php

namespace App\Livewire\Faculty\Courses;

use App\Models\Course;
use App\Models\Faculty;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Students extends Component
{
    public Course $course;

    public string $sortBy = 'name';

    public string $sortDirection = 'asc';

    public function mount(Course $course): void
    {
        $faculty = $this->faculty();

        abort_unless(
            $course->faculty_id === $faculty->id
                || $course->facultyRoles()
                ->where('faculty_id', $faculty->id)
                ->where('role', 'co_faculty')
                ->exists(),
            403
        );

        $this->course = $course;
    }

    public function render(): View
    {
        $participants = $this->course->enrollments()

            ->with([

                'student.user',

                'student.firmMemberships' => function ($query): void {

                    $query->where('course_id', $this->course->id)

                        ->with('firm');
                },

            ])

            ->orderBy('created_at')

            ->get();

        $participants = match ($this->sortBy) {

            'firm' => $this->sortDirection === 'asc'

                ? $participants->sortBy(

                    fn($enrollment) => $enrollment->student?->firmMemberships->first()?->firm?->name ?? 'ZZZZ'

                )

                : $participants->sortByDesc(

                    fn($enrollment) => $enrollment->student?->firmMemberships->first()?->firm?->name ?? ''

                ),

            'registered' => $this->sortDirection === 'asc'

                ? $participants->sortBy('created_at')

                : $participants->sortByDesc('created_at'),

            default => $this->sortDirection === 'asc'

                ? $participants->sortBy(

                    fn($enrollment) => strtolower($enrollment->student?->user?->name ?? '')

                )

                : $participants->sortByDesc(

                    fn($enrollment) => strtolower($enrollment->student?->user?->name ?? '')

                ),
        };

        return view('livewire.faculty.courses.students', [
            'participants' => $participants,
        ]);
    }

    private function faculty(): Faculty
    {
        abort_unless(auth()->check() && auth()->user()->faculty, 403);

        return auth()->user()->faculty;
    }

    public function sort(string $column): void
    {
        abort_unless(
            in_array($column, ['name', 'firm', 'registered'], true),
            400
        );

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc'
                ? 'desc'
                : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
    }
}
