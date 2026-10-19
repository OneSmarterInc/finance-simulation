<?php

namespace App\Services\Practice;

use App\Models\CfPlusAuctionEconomicsSnapshot;
use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\Student;
use App\Models\User;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\CfPlus\CfPlusAuctionEconomicsSnapshotService;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\CfPlus\CfPlusGate;
use App\Services\CfPlus\CfPlusOfferService;
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
        $studentQuarters = $source->quarters->where('quarter_number', '>=', 4);
        if ($studentQuarters->isEmpty() || $studentQuarters->contains(fn (CourseQuarter $quarter): bool => $quarter->enablements->isEmpty())) {
            throw ValidationException::withMessages(['practice' => 'Save the course schedule and decision areas before rehearsing it.']);
        }

        // The copy is one transaction, so one that fails part way leaves nothing behind. The warm-up runs once it commits.
        return $this->withSyncQueue(function () use ($faculty, $source): Course {
            $practice = DB::transaction(fn (): Course => $this->copy($faculty, $source), 1);
            // The first student quarter opens, as it would for a class, so its decision screen can be seen.
            $this->openNext($faculty, $practice);

            return $practice->fresh();
        });
    }

    /** The copy itself: setup, schedule, decision areas, the three teams, then deploy (which starts the warm-up). */
    private function copy(Faculty $faculty, Course $source): Course
    {
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
            // A run keeps the team settings it was made with (layer 5, FF7).
            'practice_team_settings' => $source->practice_team_settings,
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
                    $this->placeOffers($practice->fresh(), $quarter);
                    $this->quarters->queueClose($faculty, $quarter);
                }
                if ($quarter->fresh()->state !== CourseQuarter::CLOSED) {
                    throw ValidationException::withMessages(['practice' => "Quarter {$quarter->quarter_number} did not close. Start again, and if it happens again please report it."]);
                }
            }
            $this->openNext($faculty, $practice);
            $this->settleIfFinished($faculty, $practice->fresh());
        });

        $practice->forceFill(['practice_last_played_at' => now()])->save();

        return $practice->fresh();
    }

    /**
     * The teams' settings a run is played with (layer 5): the standard rules
     * unless the faculty member changed them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settings(Course $course): array
    {
        return TeamSettings::resolve($course->practice_team_settings);
    }

    /** @return array<string, string> each team's rule in plain words */
    public function rules(Course $course): array
    {
        return collect($this->settings($course))->map(fn (array $s, string $team): string => TeamSettings::describe($team, $s))->all();
    }

    /** @return array<string, string> each team's bidding rule (FF4, FF8) */
    public function bidRules(Course $course): array
    {
        return collect($this->settings($course))->map(fn (array $s, string $team): string => TeamSettings::describeBid($team, $s))->all();
    }

    /** @return list<string> the teams playing something other than their standard rules */
    public function customTeams(Course $course): array
    {
        return array_keys(array_filter($this->settings($course), fn (array $s, string $team): bool => ! TeamSettings::isStandard($team, $s), ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Save the teams' settings on the course being rehearsed (FF7): if there
     * is a run, it is started again and replayed to the quarter it had
     * reached, so the table never mixes two sets of rules.
     *
     * @param  array<string, array<string, mixed>>  $input
     */
    public function saveSettings(Faculty $faculty, Course $source, array $input): ?Course
    {
        $this->courses->authorize($faculty, $source);
        if ($source->is_practice) {
            throw ValidationException::withMessages(['practice' => 'Team settings belong to the course being rehearsed.']);
        }
        $clean = TeamSettings::validate($input);
        $changes = array_filter(collect($clean)->map(fn (array $s, string $team): array => TeamSettings::differences($team, $s))->all());
        $source->forceFill(['practice_team_settings' => $changes === [] ? null : $changes])->save();

        $run = $this->current($source);
        if (! $run) {
            return null;
        }
        $reached = $this->playedTo($run);
        $fresh = $this->startAgain($faculty, $run);

        return $reached >= 4 ? $this->playTo($faculty, $fresh, $reached) : $fresh;
    }

    /**
     * In an offer quarter, every reference team places a sealed offer for each
     * rival by its rule (FF4), capped at what it can fund. Offers are placed
     * again each offer quarter, so they follow the funding the team has then.
     */
    private function placeOffers(Course $practice, CourseQuarter $quarter): void
    {
        if (! $practice->cf_plus_enabled) {
            return;
        }
        $start = app(CfPlusGate::class)->evaluationStartQuarter($practice);
        if ($quarter->quarter_number < $start || $quarter->quarter_number >= $start + (int) $practice->adversarial_quarters) {
            return;
        }
        $economics = app(CfPlusAuctionEconomicsSnapshotService::class)->forCourse($practice)->economics()->get()->keyBy('firm_id');
        $offers = app(CfPlusOfferService::class);
        $settings = $this->settings($practice);
        $firms = $practice->firms()->get();
        foreach ($firms as $bidder) {
            foreach ($firms->where('id', '!=', $bidder->id) as $target) {
                $e = $economics->get($target->id);
                if (! $e) {
                    continue;
                }
                $rule = $settings[strtolower((string) $bidder->code)] ?? null;
                if (! $rule) {
                    continue;
                }
                $base = $rule['bid_basis'] === 'true_worth' ? (float) $e->displayed_true_stake_worth : (float) $e->consultant_stake_value;
                $wanted = $rule['bid_pct'] === 100 ? $base : ($rule['bid_pct'] / 100) * $base;
                $cap = (float) $offers->fundingContext($practice, $bidder, $target)['maximum_fundable_offer'];
                $offers->submit($practice, $bidder, $target, max(0.0, floor(min($wanted, $cap) * 100) / 100));
            }
        }
    }

    /** After the last quarter closes, the run settles the evaluation stage, as the instructor would. */
    private function settleIfFinished(Faculty $faculty, Course $practice): void
    {
        if ($practice->cf_plus_enabled && ! $practice->quarters()->where('quarter_number', '>=', 4)->where('state', '!=', CourseQuarter::CLOSED)->exists()
            && ! CfPlusAuctionResult::query()->where('course_id', $practice->id)->exists()) {
            app(CfPlusAuctionSettlementService::class)->settle($faculty, $practice);
        }
    }

    /**
     * The evaluation stage of a practice run, for the Practice run page:
     * what each team is worth to the consultant and in truth, the offers the
     * teams placed and why, and once settled, who won what at what price.
     *
     * @return array{opens_with: int, open: bool, settled: bool, firms: list<array<string, mixed>>, offers: list<array<string, mixed>>, results: list<array<string, mixed>>}|null
     */
    public function evaluation(Course $practice): ?array
    {
        if (! $practice->cf_plus_enabled) {
            return null;
        }
        $start = app(CfPlusGate::class)->evaluationStartQuarter($practice);
        $snapshot = CfPlusAuctionEconomicsSnapshot::query()->where('course_id', $practice->id)->first();
        $names = $practice->firms()->get()->mapWithKeys(fn (Firm $f) => [$f->id => ReferenceTeams::NAMES[strtolower((string) $f->code)] ?? $f->name]);
        $team = fn (string $firmId): string => strtolower((string) $practice->firms()->whereKey($firmId)->value('code'));
        $out = ['opens_with' => $start, 'open' => $snapshot !== null, 'settled' => false, 'firms' => [], 'offers' => [], 'results' => []];
        if (! $snapshot) {
            return $out;
        }
        foreach ($snapshot->economics()->get() as $e) {
            $out['firms'][] = ['team' => $team($e->firm_id), 'name' => $names[$e->firm_id], 'consultant' => (float) $e->full_consultant_value, 'true_worth' => (float) $e->displayed_full_true_worth,
                'consultant_stake' => (float) $e->consultant_stake_value, 'true_stake' => (float) $e->displayed_true_stake_worth];
        }
        usort($out['firms'], fn ($a, $b) => array_search($a['team'], ReferenceTeams::TEAMS, true) <=> array_search($b['team'], ReferenceTeams::TEAMS, true));
        foreach (CfPlusOffer::query()->where('course_id', $practice->id)->get() as $o) {
            $out['offers'][] = ['bidder' => $team($o->bidder_firm_id), 'bidder_name' => $names[$o->bidder_firm_id], 'target' => $team($o->target_firm_id), 'target_name' => $names[$o->target_firm_id],
                'amount' => (float) $o->offered_amount, 'capped' => (float) $o->offered_amount >= (float) $o->fundable_cap - 0.01];
        }
        $order = fn (string $team): int => (int) array_search($team, ReferenceTeams::TEAMS, true);
        usort($out['offers'], fn ($a, $b) => [$order($a['target']), -$a['amount']] <=> [$order($b['target']), -$b['amount']]);
        $results = CfPlusAuctionResult::query()->where('course_id', $practice->id)->get();
        $out['settled'] = $results->isNotEmpty();
        foreach ($results as $r) {
            $out['results'][] = ['target' => $team($r->target_firm_id), 'target_name' => $names[$r->target_firm_id],
                'winner' => $r->winning_bidder_firm_id ? $team($r->winning_bidder_firm_id) : null, 'winner_name' => $r->winning_bidder_firm_id ? $names[$r->winning_bidder_firm_id] : null,
                'price' => $r->price_paid !== null ? (float) $r->price_paid : null, 'true_stake' => (float) $r->true_stake_worth,
                'difference' => $r->price_paid !== null ? (float) $r->price_paid - (float) $r->true_stake_worth : null];
        }
        usort($out['results'], fn ($a, $b) => array_search($a['target'], ReferenceTeams::TEAMS, true) <=> array_search($b['target'], ReferenceTeams::TEAMS, true));

        return $out;
    }

    /**
     * Open the quarter after the last one played, so the run stands where a
     * class would: results in, next decisions open. The next Play has every
     * team decide it by its rules.
     */
    private function openNext(Faculty $faculty, Course $practice): void
    {
        $next = $practice->quarters()->where('quarter_number', $this->playedTo($practice) + 1)->first();
        if ($next && $next->state === CourseQuarter::LOCKED) {
            $this->quarters->open($faculty, $next);
        }
    }

    /** Start again: the old run is removed and a fresh copy of the course is made. */
    public function startAgain(Faculty $faculty, Course $practice): Course
    {
        $this->assertPractice($faculty, $practice);
        $source = Course::query()->findOrFail($practice->practice_source_course_id);
        $this->purge($practice);

        return $this->create($faculty, $source);
    }

    /** FF6: a practice run is removed this many days after it was last played. */
    public const KEEP_DAYS = 30;

    /** The faculty member removes their practice run (FF6). */
    public function remove(Faculty $faculty, Course $practice): void
    {
        $this->assertPractice($faculty, $practice);
        $this->purge($practice);
    }

    /** When a run will be removed if it isn't played again. */
    public function removedOn(Course $practice): CarbonImmutable
    {
        return CarbonImmutable::instance($practice->practice_last_played_at ?? $practice->created_at)->addDays(self::KEEP_DAYS);
    }

    /**
     * The daily tidy-up (FF6): removes practice runs not played for 30 days,
     * and any left hidden by an earlier version of Start again.
     *
     * @return int how many runs were removed
     */
    public function tidy(): int
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);
        $removed = 0;
        Course::withTrashed()
            ->where('is_practice', true)
            ->where(fn ($q) => $q->whereNotNull('deleted_at')
                ->orWhere('practice_last_played_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('practice_last_played_at')->where('created_at', '<', $cutoff)))
            ->orderBy('created_at')
            ->each(function (Course $practice) use (&$removed): void {
                $this->purge($practice);
                $removed++;
            });

        return $removed;
    }

    /**
     * Remove a practice run for good: its evaluation stage, results,
     * decisions, firms, practice students and their sign-ins, quarters and
     * the course itself. The log of view-as switches and Ask Claude's
     * question log are kept: they are records of what faculty did, and Ask
     * Claude's log is pruned after 90 days anyway. The database guards allow this for practice runs
     * only (migration 2026_10_19_000002). The frozen economics go first, so
     * the roster guards on firms and memberships no longer apply.
     */
    private function purge(Course $practice): void
    {
        if (! $practice->is_practice) {
            throw new \LogicException('Only a practice run can be removed this way.');
        }
        DB::transaction(function () use ($practice): void {
            $id = $practice->id;
            $firmIds = DB::table('firms')->where('course_id', $id)->pluck('id');
            $quarterIds = DB::table('course_quarters')->where('course_id', $id)->pluck('id');
            $studentIds = DB::table('students')->where('is_practice', true)
                ->whereIn('id', DB::table('enrollments')->where('course_id', $id)->select('student_id'))
                ->pluck('id');
            $userIds = DB::table('students')->whereIn('id', $studentIds)->pluck('user_id');

            foreach ([
                'cf_plus_auction_results', 'cf_plus_offers', 'cf_plus_auction_economics', 'cf_plus_auction_economics_snapshots',
                'cf_plus_consultant_reports', 'cf_plus_disclosure_snapshots',
                'firm_quarter_closes', 'firm_quarter_decisions', 'firm_accounting_election_requests', 'firm_accounting_elections',
                'firm_engine_results', 'firm_quarter_interpretations', 'firm_quarter_readings', 'firm_rival_analyses', 'firm_memberships', 'enrollments',
            ] as $table) {
                DB::table($table)->where('course_id', $id)->delete();
            }
            DB::table('firms')->whereIn('id', $firmIds)->delete();
            DB::table('course_decision_enablements')->whereIn('course_quarter_id', $quarterIds)->delete();
            DB::table('course_quarters')->where('course_id', $id)->delete();
            DB::table('course_faculty_roles')->where('course_id', $id)->delete();
            DB::table('students')->whereIn('id', $studentIds)->delete();
            DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('courses')->where('id', $id)->delete();
        });
    }

    /** The quarter a practice run has played to: its last closed student quarter, or 3 once the warm-up is in. */
    public function playedTo(Course $practice): int
    {
        return (int) ($practice->quarters()->where('quarter_number', '>=', 4)->where('state', CourseQuarter::CLOSED)->max('quarter_number') ?? 3);
    }

    /**
     * The side-by-side table of the Practice run page: for each team and each
     * played quarter, the figures faculty compare. Value created is ending
     * equity plus every dividend paid from Quarter 4
     * (economics/cfmodel/course.py, value_created).
     *
     * @return array<string, array<int, array<string, float>>> team => quarter => measure => value
     */
    public function scoreboard(Course $practice): array
    {
        $out = array_fill_keys(ReferenceTeams::TEAMS, []);
        $firms = $practice->firms()->with(['engineResults' => fn ($q) => $q->orderBy('quarter_number')])->get();
        foreach ($firms as $firm) {
            $team = strtolower((string) $firm->code);
            if (! array_key_exists($team, $out)) {
                continue;
            }
            $paid = 0.0;
            foreach ($firm->engineResults as $result) {
                $f = $result->fields;
                $value = fn (string $key): float => (float) ($f[$key] ?? 0);
                $sum = fn (string $prefix): float => array_sum(array_map(fn ($r, $p) => $value("{$prefix}_{$r}_{$p}"), ['r1', 'r2', 'r3', 'r1', 'r2', 'r3'], ['p1', 'p1', 'p1', 'p2', 'p2', 'p2']));
                if ($result->quarter_number >= 4) {
                    $paid += -$value('cf_equity');
                }
                $out[$team][$result->quarter_number] = [
                    'value' => $value('bs_common') + $value('bs_retained') + $value('bs_curr_ni') + $paid,
                    'net_income' => $value('cf_net_income'),
                    'cash' => $value('bs_cash'),
                    'ordered' => $sum('n2_ord'),
                    'sold' => $sum('n2_sold'),
                    'short' => $value('n2_short_p1') + $value('n2_short_p2'),
                    'stock' => $value('n3_units_p1') + $value('n3_units_p2'),
                    'inventory' => $value('bs_inventory'),
                    'warranty' => $value('bs_warranty_res'),
                    'dividend' => -$value('cf_equity'),
                ];
            }
        }

        return $out;
    }

    /**
     * Value created by each team after each played student quarter.
     *
     * @return array<int, array<string, float>> quarter => team => dollars
     */
    public function valueCreated(Course $practice): array
    {
        $out = [];
        foreach ($this->scoreboard($practice) as $team => $quarters) {
            foreach ($quarters as $quarter => $row) {
                if ($quarter >= 4) {
                    $out[$quarter][$team] = $row['value'];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * The team leading on value created in each played student quarter.
     *
     * @return array<int, string> quarter => team
     */
    public function leaders(Course $practice): array
    {
        $out = [];
        foreach ($this->valueCreated($practice) as $quarter => $values) {
            arsort($values);
            $out[$quarter] = (string) array_key_first($values);
        }

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
        $settings = $this->settings($course)[$team];
        $decision = $this->teams->decide($team, $quarter->quarter_number, $prior, $this->decisions->parameters($firm), $course->startingElections(), $settings);

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

        $share = $settings['dividend_pct'] / 100;
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
            'name' => ReferenceTeams::NAMES[$team].' student',
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
