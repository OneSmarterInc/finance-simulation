<?php

namespace App\Services\Practice;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\PracticeViewSwitch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * U9 view-as (design note FF5 a). A faculty member looks at a practice run
 * through a reference team's eyes by signing in as that team's practice
 * student for the rest of the session, until they switch back.
 *
 * Only practice runs, only their owner, only their practice students. Every
 * switch is logged. While viewing, the session holds who to switch back to,
 * and RestrictPracticeView keeps the session on that team's student pages.
 */
class PracticeViewAs
{
    public const SESSION_KEY = 'practice_view_as';

    /** The student pages a faculty member can open while viewing as a team. */
    public const ALLOWED_ROUTES = [
        'firms.dashboard', 'firms.history', 'student.decisions.workspace', 'student.accounting-election',
        'student.cf-plus.evaluation', 'student.courses', 'guide', 'practice.back',
    ];

    /** @return Firm the team's firm, whose dashboard the faculty member lands on */
    public function start(Request $request, Faculty $faculty, Course $practice, string $team): Firm
    {
        if (! $practice->is_practice || $practice->faculty_id !== $faculty->id || $practice->trashed()) {
            throw new HttpException(404);
        }
        if ($this->current($request) !== null) {
            throw new HttpException(409, 'Switch back to the faculty view first.');
        }
        $firm = $practice->firms()->where('code', strtoupper($team))->with('memberships.student.user')->first();
        $student = $firm?->memberships->first()?->student;
        if (! in_array($team, ReferenceTeams::TEAMS, true) || ! $student?->is_practice || ! $student->user) {
            throw new HttpException(404);
        }

        $back = $request->user();
        Auth::login($student->user);
        $request->session()->regenerateToken();
        $request->session()->put(self::SESSION_KEY, [
            'faculty_user_id' => $back->id,
            'faculty_id' => $faculty->id,
            'student_user_id' => $student->user->id,
            'course_id' => $practice->id,
            'source_course_id' => $practice->practice_source_course_id,
            'firm_id' => $firm->id,
            'team' => $team,
        ]);
        $this->log($request, $faculty->id, $practice->id, $firm->id, $team, PracticeViewSwitch::VIEW_AS);

        return $firm;
    }

    /** Switch back to the faculty member. Returns the course whose Practice run page to show. */
    public function stop(Request $request): ?string
    {
        $view = $this->current($request);
        if ($view === null) {
            return null;
        }
        $faculty = User::query()->findOrFail($view['faculty_user_id']);
        Auth::login($faculty);
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->regenerateToken();
        $this->log($request, $view['faculty_id'], $view['course_id'], $view['firm_id'], $view['team'], PracticeViewSwitch::BACK);

        return $view['source_course_id'];
    }

    /**
     * The view in progress, if the signed-in user is the practice student
     * it was started for.
     *
     * @return array{faculty_user_id: string, faculty_id: string, student_user_id: string, course_id: string, source_course_id: string, firm_id: string, team: string}|null
     */
    public function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $view = $request->session()->get(self::SESSION_KEY);
        if (! is_array($view)) {
            return null;
        }
        if ($request->user()?->id !== $view['student_user_id']) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $view;
    }

    private function log(Request $request, string $facultyId, string $courseId, ?string $firmId, ?string $team, string $action): void
    {
        PracticeViewSwitch::query()->create([
            'faculty_id' => $facultyId, 'course_id' => $courseId, 'firm_id' => $firmId,
            'team' => $team, 'action' => $action, 'ip_address' => $request->ip(),
        ]);
    }
}
