<?php

namespace App\Livewire;

use App\Models\CourseFacultyRole;
use App\Models\Firm;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.student')]
class InheritedHistory extends Component
{
    public Firm $firm;

    public function mount(Firm $firm): void
    {
        $user = auth()->user();

        abort_unless($user !== null, 403);

        $facultyAccess = $user->faculty !== null
            && $user->faculty->institution_id === $firm->institution_id
            && (
                $firm->course()->where('faculty_id', $user->faculty->id)->exists()
                || CourseFacultyRole::query()
                    ->where('course_id', $firm->course_id)
                    ->where('faculty_id', $user->faculty->id)
                    ->exists()
            );

        $studentAccess = $user->student !== null
            && $user->student->institution_id === $firm->institution_id
            && $firm->memberships()
                ->where('student_id', $user->student->id)
                ->exists();

        abort_unless($facultyAccess || $studentAccess, 403);

        $this->firm = $firm->load('course');
    }

    public function render(): View
    {
        return view('livewire.inherited-history', [
            'results' => $this->firm
                ->engineResults()
                ->where('quarter_number', '<=', 3)
                ->orderBy('quarter_number')
                ->get(),
        ]);
    }
}