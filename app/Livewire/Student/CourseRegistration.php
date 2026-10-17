<?php

namespace App\Livewire\Student;

use App\Models\Course;
use App\Services\Students\StudentCourseRegistrationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CourseRegistration extends Component
{
    public string $token = '';

    public ?Course $course = null;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $registrationSuccessful = false;

    public bool $alreadyEnrolled = false;

    public bool $accountCreated = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->course = Course::query()
            ->with([
                'institution',
                'faculty.user',
                'scenario',
            ])
            ->where('registration_token', $token)
            ->where('status', Course::STATUS_DEPLOYED)
            ->first();

        /*
         * Do not expose whether a particular token exists.
         *
         * Invalid/expired registration links should result in
         * a generic 404 response.
         */
        if (! $this->course) {
            abort(404);
        }
    }

    public function getRegistrationOpenProperty(): bool
    {
        if (! $this->course) {
            return false;
        }

        if ($this->course->status !== Course::STATUS_DEPLOYED) {
            return false;
        }

        if (! $this->course->registration_enabled) {
            return false;
        }

        if (
            $this->course->starts_on &&
            now()->startOfDay()->lt(
                $this->course->starts_on->startOfDay()
            )
        ) {
            return false;
        }

        if (
            $this->course->ends_on &&
            now()->startOfDay()->gt(
                $this->course->ends_on->endOfDay()
            )
        ) {
            return false;
        }

        return true;
    }

    public function register(
        StudentCourseRegistrationService $registrationService
    ): void {
        $this->resetErrorBag();

        $this->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        if (! $this->course) {
            abort(404);
        }

        try {
            $result = $registrationService->register(
                course: $this->course,
                name: $this->name,
                email: $this->email,
                password: $this->password,
            );

            $this->accountCreated = $result['created_account'];
            $this->alreadyEnrolled = $result['already_enrolled'];

            /*
             * Authenticate the student after successful registration.
             *
             * The service has already verified that:
             *
             * 1. Existing admin accounts are rejected.
             * 2. Existing faculty accounts are rejected.
             * 3. Existing student accounts belong to the
             *    correct institution.
             */
            Auth::login($result['user']);

            request()->session()->regenerate();

            $this->registrationSuccessful = true;

            $this->redirectRoute('student.courses');
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    public function render(): View
    {
        return view('livewire.student.course-registration')
            ->layout('layouts.public');
    }
}