<?php

namespace App\Livewire\Student;

use App\Models\Course;
use Livewire\Component;

class CourseRegistration extends Component
{
    public string $token = '';

    public ?Course $course = null;

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->course = Course::query()
            ->where('registration_token', $token)
            ->where('status', Course::STATUS_DEPLOYED)
            ->firstOrFail();
    }

    public function render()
    {
        return view('livewire.student.course-registration');
    }
}