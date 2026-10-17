<?php

namespace App\Livewire;

use App\Services\Courses\CourseStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * The Guide in both sidebars (walkthrough item 80, U2): the faculty guide
 * and one-pagers for faculty, the student guide for students.
 */
class Guide extends Component
{
    public function render(): View
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->faculty || $user->student), 403);

        $read = fn (string $name): string => Str::markdown(
            (string) file_get_contents(resource_path("guides/{$name}.md")),
            ['html_input' => 'escape', 'allow_unsafe_links' => false],
        );

        if ($user->faculty) {
            $sections = [
                'Running your course' => $read('faculty-guide'),
                'Flexee Financial Accounting' => $read('one-pager'),
                'Flexee Financial Accounting+' => $read('one-pager-plus'),
            ];

            return view('livewire.guide', ['sections' => $sections, 'audience' => 'faculty'])
                ->layout('layouts.app', ['title' => 'Guide']);
        }

        $sections = ['How the course works' => $read('student-guide')];
        $plusOpen = $user->student->firmMemberships()->with('firm.course')->get()
            ->contains(fn ($membership) => $membership->firm?->course?->cf_plus_enabled
                && CourseStatus::for($membership->firm->course)->evaluationOpen());
        if ($plusOpen) {
            $sections['The evaluation stage'] = $read('student-guide-plus');
        }

        return view('livewire.guide', ['sections' => $sections, 'audience' => 'student'])
            ->layout('layouts.student', ['title' => 'Guide']);
    }
}
