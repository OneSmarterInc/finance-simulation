<?php

namespace App\Support\Ai;

use App\Models\Course;
use App\Models\Firm;
use App\Services\Ai\AskClaude;
use Illuminate\Http\Request;

/**
 * Which faculty pages carry Ask Claude, what each tells Claude it's looking
 * at, and the questions it suggests there (U1: every faculty page, with its
 * course, quarter and firm). Layer 1 covers the course pages; the faculty
 * dashboard, which spans several courses, follows with the results lookups.
 */
final class AskClaudePages
{
    /** @var array<string, array{0: string, 1: list<string>}> route => [label, suggestions] */
    private const PAGES = [
        'faculty.courses.wizard' => ['Course setup', ['When does each decision area open to students?', 'Which accounting methods do teams start with?', 'How long does each quarter last?']],
        'faculty.courses.firms' => ['Firms', ['Which firms haven\'t submitted this quarter?', 'Can I move a student to another firm?', 'What happens to a firm that doesn\'t submit?']],
        'faculty.courses.students' => ['Students', ['How do students join this course?', 'What does a student see before their payment is confirmed?']],
        'faculty.courses.quarters' => ['Quarters', ['Who still has to submit this quarter?', 'What happens when the deadline passes?', 'When does the next quarter open?']],
        'faculty.courses.quarter-results' => ['Quarter results', ['What does each tab on this page show?', 'When will the next results arrive?']],
        'faculty.courses.accounting-election-requests' => ['Accounting method requests', ['What happens if I approve a request?', 'How should I judge a request to change methods?', 'How many requests are waiting?']],
        'faculty.courses.cf-plus-auction' => ['Evaluation stage', ['What will students see when the evaluation stage opens?', 'How is the consultant\'s valuation worked out?', 'When does the evaluation stage open?']],
        'faculty.courses.practice' => ['Practice run', ['What do the three reference teams do differently?', 'How far has the practice run been played?', 'Why should the Careful team lead?']],
        'firms.dashboard' => ['Firm dashboard', ['Has this firm submitted this quarter?', 'What does this page show students?']],
        'firms.history' => ['Firm history', ['What are the warm-up quarters?', 'What does this page show students?']],
    ];

    /**
     * @return array{course: Course, context: array{page: string, label: string, details: array<string, string>}, suggestions: list<string>}|null
     */
    public static function for(Request $request): ?array
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();
        if (! $user?->faculty || ! isset(self::PAGES[$name])) {
            return null;
        }
        $course = $request->route('course');
        $firm = $request->route('firm');
        if (! $course instanceof Course && $firm instanceof Firm) {
            $course = $firm->course;
        }
        if (! $course instanceof Course || ! $course->exists || ! AskClaude::available($course)) {
            return null;
        }
        $teaches = $course->faculty_id === $user->faculty->id || $course->facultyRoles()->where('faculty_id', $user->faculty->id)->exists();
        if (! $teaches) {
            return null;
        }

        [$label, $suggestions] = self::PAGES[$name];
        $details = [];
        if ($firm instanceof Firm) {
            $details['firm'] = $firm->name;
        }
        if ($name === 'faculty.courses.quarter-results') {
            if (($id = $request->query('firm')) && ($picked = $course->firms()->whereKey($id)->first())) {
                $details['firm'] = $picked->name;
            }
            if (is_numeric($request->query('quarter'))) {
                $details['quarter'] = 'Quarter '.(int) $request->query('quarter');
            }
        }
        if ($name === 'faculty.courses.cf-plus-auction' && ! $course->cf_plus_enabled) {
            return null;
        }

        return ['course' => $course, 'context' => ['page' => $name, 'label' => $label, 'details' => $details], 'suggestions' => $suggestions];
    }
}
