<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Interpretations\InterpretationService;
use App\Support\Dates;
use App\Support\Export\XlsxWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * U11 (IN3): every team explanation in a course, one row per team per
 * quarter, for grading outside Flexee. Faculty who teach the course only.
 */
class ExplanationsDownloadController extends Controller
{
    public function __invoke(Request $request, Course $course, InterpretationService $service): BinaryFileResponse
    {
        $faculty = $request->user()?->faculty;
        abort_unless($faculty !== null, 403);
        try {
            $service->authorizeFaculty($faculty, $course);
        } catch (AuthorizationException) {
            abort(403);
        }
        abort_if($course->is_practice, 404);

        $text = XlsxWriter::TEXT;
        $rows = [[['Quarter', XlsxWriter::BOLD], ['Firm', XlsxWriter::BOLD], ['Status', XlsxWriter::BOLD], ['Submitted by', XlsxWriter::BOLD], ['Submitted', XlsxWriter::BOLD],
            ['What changed?', XlsxWriter::BOLD], ['Why did it change?', XlsxWriter::BOLD], ['What do the notes show?', XlsxWriter::BOLD], ["Instructor's comment", XlsxWriter::BOLD]]];
        foreach ($service->forCourse($course) as $row) {
            $rows[] = [
                [$row->courseQuarter?->quarter_number, $text], [$row->firm?->name, $text], [$row->isSubmitted() ? 'Submitted' : 'Draft, not submitted', $text],
                [$row->submittedBy?->user?->name, $text], [$row->submitted_at ? Dates::dateTime($row->submitted_at) : null, $text],
                [$row->answers['changed'] ?? null, $text], [$row->answers['why'] ?? null, $text], [$row->answers['notes'] ?? null, $text], [$row->faculty_comment, $text],
            ];
        }
        $book = new XlsxWriter;
        $book->addSheet('Team explanations', $rows, [9, 22, 18, 20, 24, 60, 60, 60, 50]);
        $path = tempnam(sys_get_temp_dir(), 'flexee-explanations-');
        $book->save($path);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $course->name), '-') ?: 'course';

        return response()->download($path, $slug.'-team-explanations-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
