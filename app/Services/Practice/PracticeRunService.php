<?php

namespace App\Services\Practice;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Courses\CourseConfigurationService;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Firms\FirmAssignmentService;
use App\Services\Quarters\QuarterManagementService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * U9 practice runs (design note FF1 to FF3, FF6).
 *
 * A practice run is a private copy of a faculty member's course: the same
 * schedule, decision areas, starting methods, method-change setting and
 * evaluation stage. Three reference teams play it through the real pipeline
 * (warm-up, decisions, quarter closes), so every screen shows what a class
 * would see. Practice runs are kept out of every list, count, report and the
 * scheduler, and are played only when the faculty member asks.
 */
class PracticeRunService
{
    public function __construct(
        private readonly CourseConfigurationService $courses,
        private readonly FirmAssignmentService $firms,
        private readonly QuarterManagementService $quarters,
        private readonly FirmDecisionService $decisions,
        private readonly ReferenceTeams $teams,
    ) {}

    /** The faculty member's practice run of a course, if there is one. */
    public function current(Course $source): ?Course
    {
        return Course::query()
            ->where('is_practice', true)
            ->where('practice_source_course_id', $source->id)
            ->latest()
            ->first();
    }

    public function create(Faculty $faculty, Course $source): Course
    {
        $this->courses->authorize($faculty, $source);
        if ($source->is_practice) {
            throw ValidationException::withMessages(['practice' => 'A practice run is made from a course, not from another practice run.']);
        }
        if ($source->engineVersion() !== 2) {
            throw ValidationException::withMessages(['practice' => 'Practice runs need a course on the current engine. This course was created before October 2026.']);
        }
        $source->loadMissing(['quarters.enablements.category']);
        if ($source->quarters->where('quarter_number', '>=', 4)->isEmpty()) {
            throw ValidationException::withMessages(['practice' => 'Set the course schedule and decision areas before rehearsing it.']);
        }

        // One transaction, so a copy that fails part way leaves nothing behind. The warm-up runs once it commits.
        return $this->withSyncQueue(fn (): Course => DB::transaction(function () use ($faculty, $source): Course {
            $practice = $this->courses->saveSetup($faculty, [
                'name' => 'Practice run: '.$source->name,
                'institution_id' => $source->institution_id,
                'scenario_id' => $source->scenario_id,
                'starts_on' => $source->starts_on->toDateString(),
                'ends_on' => $source->ends_on->toDateString(),
                'payment_mode' => null,
                'cf_plus_enabled' => (bool) $source->cf_plus_enabled,
                'accounting_election_enabled' => (bool) $source->accounting_election_enabled,
                'build_quarters' => $source->build_quarters,
                'adversarial_quarters' => $source->adversarial_quarters,
                'post_bid_quarters' => $source->post_bid_quarters,
            ]);
            $practice->forceFill([
                'is_practice' => true,
                'practice_source_course_id' => $source->id,
                'practice_last_played_at' => now(),
                'engine_version' => $source->engineVersion(),
                'starting_elections' => $source->startingElections(),
                'registration_enabled' => false,
            ])->save();

            $this->courses->saveSchedule($faculty, $practice, $source->quarters->sortBy('quarter_number')->map(fn (CourseQuarter $quarter): array => [
                'quarter_number' => $quarter->quarter_number,
                'starts_on' => $quarter->starts_on->format('Y-m-d'),
                'ends_on' => $quarter->ends_on->format('Y-m-d'),
                // saveSchedule reads a deadline in the display time zone, so hand it back that way.
                'decision_deadline_at' => $quarter->decision_deadline_at
                    ? CarbonImmutable::instance($quarter->decision_deadline_at)->setTimezone(Dates::zone())->format('Y-m-d H:i:s')
                    : $quarter->ends_on->format('Y-m-d'),
            ])->values()->all());

            $matrix = [];
            foreach ($source->quarters->where('quarter_number', '>=', 4) as $quarter) {
                foreach ($quarter->enablements as $row) {
                    $matrix[$quarter->quarter_number][$row->category->code] = (bool) $row->enabled;
                }
            }
            $this->courses->saveEnablements($faculty, $practice, $matrix);
            $this->courses->saveFacultyRoles($faculty, $practice, []);

            foreach (ReferenceTeams::TEAMS as $team) {
                $firm = $this->firms->createFirm($faculty, $practice, strtoupper($team), ReferenceTeams::NAMES[$team]);
                $student = $this->practiceStudent($practice, $team);
                $enrollment = $this->firms->enroll($faculty, $practice, $student);
                $enrollment->forceFill(['paid_at' => now()])->save();
                $this->firms->assign($faculty, $practice, $firm, $student);
            }

            return $this->courses->deploy($faculty, $practice->fresh());
        }, 1))->fresh();
    }

    /**
     * Play the practice run forward to the end of Quarter $target: every
     * reference team decides by its rules and each quarter closes through
     * the class's pipeline. Going back means starting again.
     */
    public function playTo(Faculty $faculty, Course $practice, int $target): Course
    {
        $this->assertPractice($faculty, $practice);
        $last = (int) $practice->quarters()->max('quarter_number');
        $target = min($target, $last);

        $this->withSyncQueue(function () use ($faculty, $practice, $target): void {
            $quarters = $practice->quarters()->where('quarter_number', '>=', 4)->where('quarter_number', '<=', $target)->orderBy('quarter_number')->get();
            foreach ($quarters as $quarter) {
                $quarter->refresh();
                if ($quarter->state === CourseQuarter::CLOSED) {
                    continue;
                }
                if ($quarter->state === CourseQuarter::LOCKED) {
                    $this->quarters->open($faculty, $quarter);
                    $quarter->refresh();
                }
                if ($quarter->state === CourseQuarter::OPEN) {
                    foreach ($practice->firms()->with('memberships.student')->get() as $firm) {
                        $this->decide($firm, $quarter);
                    }
                    $this->quarters->queueClose($faculty, $quarter);
                }
                if ($quarter->fresh()->state !== CourseQuarter::CLOSED) {
                    throw ValidationException::withMessages(['practice' => "Quarter {$quarter->quarter_number} did not close. Open the Quarters page to see which firm failed."]);
                }
            }
        });

        $practice->forceFill(['practice_last_played_at' => now()])->save();

        return $practice->fresh();
    }

    /** Start again: the old run is removed and a fresh copy of the course is made. */
    public function startAgain(Faculty $faculty, Course $practice): Course
    {
        $this->assertPractice($faculty, $practice);
        $source = Course::query()->findOrFail($practice->practice_source_course_id);
        $practice->delete();

        return $this->create($faculty, $source);
    }

    /** The quarter a practice run has played to: its last closed student quarter, or 3 once the warm-up is in. */
    public function playedTo(Course $practice): int
    {
        return (int) ($practice->quarters()->where('quarter_number', '>=', 4)->where('state', CourseQuarter::CLOSED)->max('quarter_number') ?? 3);
    }

    /**
     * Value created by each team after each played quarter: ending equity
     * plus every dividend paid from Quarter 4 (economics/cfmodel/course.py).
     *
     * @return array<int, array<string, float>> quarter => team => dollars
     */
    public function valueCreated(Course $practice): array
    {
        $out = [];
        foreach ($practice->firms()->with(['engineResults' => fn ($q) => $q->where('quarter_number', '>=', 4)->orderBy('quarter_number')])->get() as $firm) {
            $team = strtolower((string) $firm->code);
            $paid = 0.0;
            foreach ($firm->engineResults as $result) {
                $f = $result->fields;
                $paid += -(float) $f['cf_equity'];
                $out[$result->quarter_number][$team] = (float) $f['bs_common'] + (float) $f['bs_retained'] + (float) $f['bs_curr_ni'] + $paid;
            }
        }
        ksort($out);

        return $out;
    }

    private function decide(Firm $firm, CourseQuarter $quarter): void
    {
        $membership = $firm->memberships->first();
        $student = $membership?->student;
        $team = strtolower((string) $firm->code);
        if (! $student || ! in_array($team, ReferenceTeams::TEAMS, true)) {
            throw ValidationException::withMessages(['practice' => "Firm {$firm->name} is not a reference team."]);
        }
        $course = $firm->course;
        $prior = $firm->engineResults()->where('quarter_number', $quarter->quarter_number - 1)->firstOrFail()->fields;
        $decision = $this->teams->decide($team, $quarter->quarter_number, $prior, $this->decisions->parameters($firm), $course->startingElections());

        // Start from last quarter's decisions for every open area, then apply the team's rules.
        [$payload] = $this->decisions->carryForwardPayload($firm, $quarter);
        foreach ($payload as $category => $fields) {
            $rule = $category === 'notes_elections' ? $decision['notes'] : ($decision['payload'][$category] ?? []);
            foreach (array_keys($fields) as $key) {
                if (array_key_exists($key, $rule)) {
                    $payload[$category][$key] = $rule[$key];
                }
            }
        }

        // With method-change requests on, the team's methods are set directly: a practice run has no faculty to approve them.
        if ($course->accounting_election_enabled) {
            app(FirmAccountingElectionService::class)->setEffective($firm, $quarter, $decision['notes']);
        }

        $share = ReferenceTeams::DIVIDEND_SHARE[$team];
        $dividend = 0.0;
        if ($share > 0) {
            $maximum = (float) $this->decisions->dividendLimit($firm, $quarter, $payload, 0)->maximum;
            $dividend = floor($maximum * $share * 100 + 1e-6) / 100;
        }
        $this->decisions->submit($student, $firm, $quarter, $payload, $dividend);
    }

    private function practiceStudent(Course $practice, string $team): Student
    {
        $user = new User;
        $user->forceFill([
            'name' => ReferenceTeams::NAMES[$team].' (practice)',
            'email' => 'practice-'.Str::lower(Str::random(12)).'-'.$team.'@practice.flexee.invalid',
            // Practice students never sign in: faculty view the run through the view-as switch (FF5).
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
        ])->save();

        return Student::query()->create(['user_id' => $user->id, 'institution_id' => $practice->institution_id, 'is_practice' => true]);
    }

    private function assertPractice(Faculty $faculty, Course $practice): void
    {
        $this->courses->authorize($faculty, $practice);
        if (! $practice->is_practice) {
            throw ValidationException::withMessages(['practice' => 'Only a practice run can be played this way.']);
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function withSyncQueue(callable $work): mixed
    {
        $original = config('queue.default');
        config(['queue.default' => 'sync']);
        try {
            return $work();
        } finally {
            config(['queue.default' => $original]);
        }
    }
}
