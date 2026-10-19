<?php

namespace App\Support\Ai;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Services\Ai\AskClaude;
use Illuminate\Http\Request;

/**
 * Which faculty pages carry Ask Claude, what each tells Claude it's looking
 * at, and the questions it suggests there (U1: every faculty page, with its
 * course, quarter and firm). On the faculty dashboard, which spans several
 * courses, the panel asks which course the question is about.
 */
final class AskClaudePages
{
    /** @var array<string, array{0: string, 1: list<string>}> route => [label, suggestions] */
    private const PAGES = [
        'faculty.courses.wizard' => ['Course setup', ['When does each decision area open to students?', 'Which accounting methods do teams start with?', 'How long does each quarter last?']],
        'faculty.courses.firms' => ['Firms', ['Which firms haven\'t submitted this quarter?', 'Can I move a student to another firm?', 'What happens to a firm that doesn\'t submit?']],
        'faculty.courses.students' => ['Students', ['How do students join this course?', 'What does a student see before their payment is confirmed?']],
        'faculty.courses.quarters' => ['Quarters', ['Who still has to submit this quarter?', 'What happens when the deadline passes?', 'When does the next quarter open?']],
        'faculty.courses.quarter-results' => ['Quarter results', ['Which firm is ahead on value created?', 'Compare cash across all firms for the last three quarters.', 'Why did net income and cash move differently last quarter?']],
        'faculty.courses.accounting-election-requests' => ['Accounting method requests', ['Summarise the requests waiting for me.', 'What happens if I approve a request?', 'How should I judge a request to change methods?']],
        'faculty.courses.cf-plus-auction' => ['Evaluation stage', ['How many offers has each firm received?', 'Who paid more than the stake was worth?', 'How is the consultant\'s valuation worked out?']],
        'faculty.courses.explanations' => ['Team explanations', ['Which teams haven\'t explained last quarter yet?', 'What should a good explanation of a quarter cover?', 'How do teams submit their explanation?']],
        'faculty.courses.practice' => ['Practice run', ['Why is the Careless team\'s cash so low?', 'What do the three reference teams do differently?', 'Does the Careful team lead every quarter?']],
        'firms.dashboard' => ['Firm dashboard', ['How has this firm done quarter by quarter?', 'What did this firm decide last quarter?', 'Has this firm submitted this quarter?']],
        'firms.history' => ['Firm history', ['Where did this firm\'s cash go?', 'What are the warm-up quarters?']],
    ];

    /**
     * @return array{course: Course, context: array{page: string, label: string, details: array<string, string>}, suggestions: list<string>}|null
     */
    public static function for(Request $request): ?array
    {
        $user = $request->user();
        $name = (string) $request->route()?->getName();
        if ($name === 'dashboard' && $user?->faculty) {
            return self::dashboard($user->faculty);
        }
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

        return ['course' => $course, 'context' => ['page' => $name, 'label' => $label, 'details' => $details], 'suggestions' => $suggestions, 'options' => []];
    }

    /** The faculty dashboard spans courses, so the panel asks which one (layer 2). */
    private static function dashboard(Faculty $faculty): ?array
    {
        $courses = $faculty->teachingCourses()->filter(fn (Course $c) => AskClaude::available($c))->values();
        if ($courses->isEmpty()) {
            return null;
        }

        return [
            'course' => $courses->first(),
            'context' => ['page' => 'dashboard', 'label' => 'Dashboard', 'details' => []],
            'suggestions' => ['What needs my attention in this course?', 'Which firm has created the most value so far?', 'How did each firm\'s cash change last quarter?'],
            'options' => $courses->mapWithKeys(fn (Course $c) => [$c->id => $c->name])->all(),
        ];
    }
}
