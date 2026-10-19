<?php

namespace App\Livewire\Student;

use App\Models\CourseDebrief as DebriefRecord;
use App\Models\Firm;
use App\Services\Debrief\DebriefBuilder;
use App\Services\Debrief\DebriefCaptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * U4 (DB4): the class debrief, read-only, once the instructor has shared it
 * after the course. Hidden slides stay hidden; captions are the stored ones.
 */
#[Layout('layouts.student')]
class CourseDebrief extends Component
{
    public Firm $firm;

    public function mount(Firm $firm): void
    {
        $student = auth()->user()?->student;
        abort_unless($student && $firm->memberships()->where('student_id', $student->id)->exists(), 403);
        $this->firm = $firm->load('course');
        abort_unless(self::available($this->firm), 404);
    }

    public static function available(Firm $firm): bool
    {
        $course = $firm->course;

        return ! $course->is_practice
            && (bool) DebriefRecord::query()->where('course_id', $course->id)->value('shared')
            && DebriefBuilder::finished($course);
    }

    public function render(DebriefBuilder $builder, DebriefCaptions $captions): View
    {
        $course = $this->firm->course;
        $built = $builder->build($course);

        return view('livewire.student.course-debrief', [
            'course' => $course,
            'built' => $built,
            'captions' => $captions->captions($course, $built),
            'hidden' => (array) (DebriefRecord::query()->where('course_id', $course->id)->first()?->hidden ?? []),
        ]);
    }
}
