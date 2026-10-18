<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseFacultyRole;
use App\Support\Export\CourseResultsWorkbook;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Live walkthrough L16: the Quarter results download. One .xlsx with a
 * sheet per statement and note, every firm and every closed quarter.
 * Faculty who teach the course only.
 */
class ResultsDownloadController extends Controller
{
    public function __invoke(Request $request, Course $course): BinaryFileResponse
    {
        $faculty = $request->user()?->faculty;
        abort_unless($faculty !== null, 403);
        abort_unless($course->faculty_id === $faculty->id || CourseFacultyRole::query()
            ->where('course_id', $course->id)->where('faculty_id', $faculty->id)->where('role', 'co_faculty')->exists(), 403);
        abort_if($course->is_practice, 404);

        $path = tempnam(sys_get_temp_dir(), 'flexee-results-');
        try {
            CourseResultsWorkbook::build($course)->save($path);
        } catch (Throwable $e) {
            @unlink($path);
            report($e);
            abort(500, 'The spreadsheet could not be made. Please try again, and report it if it happens again.');
        }

        return response()->download($path, CourseResultsWorkbook::fileName($course), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
