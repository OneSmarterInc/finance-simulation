<?php

namespace App\Http\Controllers\Practice;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\PracticeViewAs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** U9 view-as: switch into a reference team's student view and back (FF5 a). */
class PracticeViewController extends Controller
{
    /** From the Practice run page of $course, view its practice run as $team. */
    public function viewAs(Request $request, Course $course, string $team, PracticeRunService $runs, PracticeViewAs $views): RedirectResponse
    {
        $faculty = $request->user()?->faculty;
        abort_unless($faculty !== null && $course->faculty_id === $faculty->id && ! $course->is_practice, 403);
        $practice = $runs->current($course);
        abort_if($practice === null, 404);

        $firm = $views->start($request, $faculty, $practice, $team);

        return redirect()->route('firms.dashboard', $firm);
    }

    public function back(Request $request, PracticeViewAs $views): RedirectResponse
    {
        $source = $views->stop($request);

        return $source
            ? redirect()->route('faculty.courses.practice', $source)
            : redirect()->route('dashboard');
    }
}
