<?php

namespace App\Http\Controllers\Faculty;

use App\Http\Controllers\Controller;
use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Services\Interpretations\InterpretationService;
use App\Services\Interpretations\RivalAnalysisService;
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
    public function __invoke(Request $request, Course $course, InterpretationService $service, RivalAnalysisService $rivals): BinaryFileResponse
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

        // U11 layer 3 (RA3): rival analyses on + courses. Before settlement, who analysed whom; the text and figures after.
        if ($course->cf_plus_enabled) {
            $settled = $rivals->settled($course);
            $results = CfPlusAuctionResult::query()->where('course_id', $course->id)->with('winner')->get()->keyBy('target_firm_id');
            $offers = CfPlusOffer::query()->where('course_id', $course->id)->get()->keyBy(fn ($o) => $o->bidder_firm_id.'|'.$o->target_firm_id);
            $money = XlsxWriter::MONEY;
            $sealed = 'Shown after settlement';
            $sheet = [[['Team', XlsxWriter::BOLD], ['Rival', XlsxWriter::BOLD], ['Status', XlsxWriter::BOLD], ['Submitted by', XlsxWriter::BOLD], ['Submitted', XlsxWriter::BOLD],
                ['What matters most?', XlsxWriter::BOLD], ["What does the consultant's number miss?", XlsxWriter::BOLD], ['What is 51% worth to you, and why?', XlsxWriter::BOLD],
                ["Team's figure for 51%", XlsxWriter::BOLD], ["Team's offer", XlsxWriter::BOLD], ['Price paid', XlsxWriter::BOLD], ['Won by', XlsxWriter::BOLD], ['True worth of 51%', XlsxWriter::BOLD], ["Instructor's comment", XlsxWriter::BOLD]]];
            foreach ($rivals->forCourse($course) as $row) {
                $result = $results->get($row->target_firm_id);
                $offer = $offers->get($row->bidder_firm_id.'|'.$row->target_firm_id);
                $sheet[] = [
                    [$row->bidder?->name, $text], [$row->target?->name, $text], [$row->isSubmitted() ? 'Submitted' : 'Draft, not submitted', $text],
                    [$row->submittedBy?->user?->name, $text], [$row->submitted_at ? Dates::dateTime($row->submitted_at) : null, $text],
                    ...($settled ? [
                        [$row->answers['matters'] ?? null, $text], [$row->answers['misses'] ?? null, $text], [$row->answers['worth'] ?? null, $text],
                        [$row->estimated_stake_value, $money], [$offer ? (float) $offer->offered_amount : null, $money],
                        [$result?->price_paid === null ? null : (float) $result->price_paid, $money], [$result?->winner?->name, $text],
                        [$result ? (float) $result->true_stake_worth : null, $money], [$row->faculty_comment, $text],
                    ] : [[$sealed, $text], [$sealed, $text], [$sealed, $text], [$sealed, $text], [$sealed, $text], [null, $text], [null, $text], [null, $text], [null, $text]]),
                ];
            }
            $book->addSheet('Rival analyses', $sheet, [22, 22, 18, 20, 24, 60, 60, 60, 20, 20, 20, 20, 20, 50]);
        }
        $path = tempnam(sys_get_temp_dir(), 'flexee-explanations-');
        $book->save($path);
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $course->name), '-') ?: 'course';

        return response()->download($path, $slug.'-team-explanations-'.now()->format('Y-m-d').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
