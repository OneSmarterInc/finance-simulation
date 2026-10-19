<?php

namespace App\Support\Navigation;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\User;
use App\Services\Practice\PracticeViewAs;
use App\Services\Practice\ReferenceTeams;
use Illuminate\Http\Request;

/**
 * Live walkthrough L4 to L6: where the signed-in person is, and who they are.
 *
 * Every signed-in page shows a breadcrumb line that starts with the area
 * ("Faculty › Course 02 › Firms › Alpha › History"), and the person's name
 * with their role at the top right ("Chuck Nemer · Faculty"). Each crumb but
 * the last links one level up.
 */
final class PageTrail
{
    public const FACULTY_COURSE_PAGES = [
        'faculty.courses.firms' => 'Firms',
        'faculty.courses.students' => 'Students',
        'faculty.courses.quarters' => 'Quarters',
        'faculty.courses.quarter-results' => 'Quarter results',
        'faculty.courses.explanations' => 'Team explanations',
        'faculty.courses.debrief' => 'Debrief',
        'faculty.courses.practice' => 'Practice run',
        'faculty.courses.accounting-election-requests' => 'Accounting methods',
        'faculty.courses.cf-plus-auction' => 'Evaluation stage',
    ];

    private const ADMIN_PAGES = [
        'admin.dashboard' => 'Dashboard',
        'admin.institutions' => 'Institutions',
        'admin.faculty' => 'Faculty',
        'admin.students' => 'Students',
        'admin.courses' => 'Courses',
    ];

    private const SETTINGS_PAGES = [
        'profile.edit' => 'Profile',
        'security.edit' => 'Security',
        'appearance.edit' => 'Appearance',
    ];

    /**
     * @return array{area: string, role: string, crumbs: list<array{label: string, url: string|null}>}
     */
    public static function for(Request $request): array
    {
        $user = $request->user();
        $route = $request->route();
        $name = (string) $route?->getName();
        $course = $route?->parameter('course');
        $firm = $route?->parameter('firm');
        $quarter = $route?->parameter('quarter');
        $course = $course instanceof Course ? $course : ($firm instanceof Firm ? $firm->course : null);
        $firm = $firm instanceof Firm ? $firm : null;

        $view = app(PracticeViewAs::class)->current($request);
        $area = self::area($user, $name, $course, $view !== null);
        $role = $view ? 'Faculty, viewing as '.(ReferenceTeams::NAMES[$view['team']] ?? 'a team') : $area;

        $crumbs = [['label' => $area, 'url' => self::home($area)]];
        $page = null;

        if ($area === 'Admin') {
            if (isset(self::ADMIN_PAGES[$name])) {
                $page = self::ADMIN_PAGES[$name];
            } elseif (str_starts_with($name, 'admin.courses.')) {
                $crumbs[] = ['label' => 'Courses', 'url' => route('admin.courses')];
                $page = $name === 'admin.courses.create' ? 'New course' : ($course?->name ?? 'Course');
            }
        } elseif ($area === 'Faculty' && $course) {
            $crumbs[] = ['label' => $course->name, 'url' => route($course->status === 'deployed' ? 'faculty.courses.quarters' : 'faculty.courses.wizard', $course)];
            if ($name === 'faculty.courses.wizard') {
                $page = $course->status === 'deployed' ? 'Review setup' : 'Course setup';
            } elseif (isset(self::FACULTY_COURSE_PAGES[$name])) {
                $page = self::FACULTY_COURSE_PAGES[$name];
            } elseif ($firm) {
                $crumbs[] = ['label' => 'Firms', 'url' => route('faculty.courses.firms', $course)];
                $crumbs[] = ['label' => $firm->name, 'url' => route('firms.dashboard', $firm)];
                $page = self::firmPage($name, $quarter);
            }
        } elseif ($area === 'Faculty' && $name === 'faculty.courses.create') {
            $page = 'New course';
        } elseif ($area === 'Student' && $firm && $course) {
            $crumbs[] = ['label' => $course->name, 'url' => route('student.courses')];
            $crumbs[] = ['label' => $firm->name, 'url' => route('firms.dashboard', $firm)];
            $page = self::firmPage($name, $quarter);
        } elseif ($name === 'student.courses') {
            $page = 'My courses';
        }

        if ($page === null) {
            $page = match (true) {
                $name === 'dashboard' => 'Dashboard',
                $name === 'guide' => 'Guide',
                isset(self::SETTINGS_PAGES[$name]) => 'Settings',
                default => null,
            };
            if (isset(self::SETTINGS_PAGES[$name])) {
                $crumbs[] = ['label' => 'Settings', 'url' => route('profile.edit')];
                $page = self::SETTINGS_PAGES[$name];
            }
        }

        if ($page !== null && $page !== $crumbs[array_key_last($crumbs)]['label']) {
            $crumbs[] = ['label' => $page, 'url' => null];
        }
        // On a firm's dashboard the firm is the page itself.
        if ($page === 'Dashboard' && $firm) {
            array_pop($crumbs);
        }
        $crumbs[array_key_last($crumbs)]['url'] = null;

        return ['area' => $area, 'role' => $role, 'crumbs' => $crumbs];
    }

    private static function area(?User $user, string $name, ?Course $course, bool $viewingAs): string
    {
        if (str_starts_with($name, 'admin.')) {
            return 'Admin';
        }
        if ($viewingAs || str_starts_with($name, 'student.') || str_starts_with($name, 'firms.')) {
            $teaches = $course && $user?->faculty && $course->faculty_id === $user->faculty->id;

            return ! $viewingAs && $teaches ? 'Faculty' : 'Student';
        }
        if ($name === 'dashboard' || str_starts_with($name, 'faculty.')) {
            return 'Faculty';
        }

        return match (true) {
            (bool) $user?->isFaculty() => 'Faculty',
            (bool) $user?->isStudent() => 'Student',
            (bool) $user?->isAdmin() => 'Admin',
            default => 'Signed in',
        };
    }

    private static function home(string $area): ?string
    {
        return match ($area) {
            'Admin' => route('admin.dashboard'),
            'Faculty' => route('dashboard'),
            'Student' => route('student.courses'),
            default => null,
        };
    }

    private static function firmPage(string $name, mixed $quarter): string
    {
        $number = $quarter instanceof CourseQuarter ? $quarter->quarter_number : null;

        return match ($name) {
            'firms.dashboard' => 'Dashboard',
            'firms.history' => 'History',
            'student.decisions.workspace' => $number ? "Quarter {$number} decisions" : 'Decisions',
            'student.accounting-election' => 'Accounting methods',
            'student.cf-plus.evaluation' => 'Evaluation stage',
            'firms.debrief' => 'Class debrief',
            default => 'Firm',
        };
    }
}
