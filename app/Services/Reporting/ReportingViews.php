<?php

namespace App\Services\Reporting;

use App\Models\AccountingRequestReview;
use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\CourseDebrief;
use App\Models\CourseQuarter;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterInterpretation;
use App\Models\FirmQuarterReading;
use App\Models\ReportingConnection;
use App\Services\Ai\AskClaude;
use App\Services\Ai\QuarterReadings;
use App\Services\Courses\CourseStatus;
use App\Services\Quarters\QuarterScheduler;
use App\Support\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * U3 (RP2): the family reporting contract, v1, as Financial Accounting
 * publishes it. Operational facts only: courses by name and institution,
 * counts, states, dates, timings, failures and AI spend. No person's name or
 * email, no team's written work and no firm's results. Practice runs (U9) are
 * left out. Every row starts with `sim`, so the family dashboard can put sims
 * side by side. Times are UTC in ISO 8601; money is US dollars to the cent.
 */
class ReportingViews
{
    public const SCHEDULER_KEY = 'flexee:reporting:scheduler_ran_at';

    public const CERTIFICATE_KEY = 'flexee:reporting:certificate_expires_at';

    /**
     * The tools, in the order the connector lists them. Each column is named
     * and described here once; the contract document is written from this.
     *
     * @return array<string, array{title: string, description: string, columns: array<string, string>}>
     */
    public static function tools(): array
    {
        return [
            'report_courses' => [
                'title' => 'Courses',
                'description' => 'One row per course (practice runs left out): its product, engine, status, where it stands, and how many firms and students it has.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'course_id' => 'The course\'s id.',
                    'course' => 'The course\'s name.',
                    'institution' => 'The institution\'s name.',
                    'product' => 'The product the course runs.',
                    'engine_version' => 'The economics engine the course runs on.',
                    'status' => 'setting_up, running or finished.',
                    'quarter_now' => 'The quarter open or closing now, else null.',
                    'quarters' => 'Quarters in the course, warm-up included.',
                    'firms' => 'Firms in the course.',
                    'students' => 'Students enrolled.',
                    'students_confirmed' => 'Enrolled students whose access is confirmed.',
                    'next_deadline_at' => 'The open quarter\'s decision deadline, else null.',
                    'created_at' => 'When the course was created.',
                    'deployed_at' => 'When the course was deployed to students, else null.',
                ],
            ],
            'report_quarters' => [
                'title' => 'Quarters',
                'description' => 'One row per course quarter: its state and deadline, when it opened and closed, how long the close took, and how many firms submitted.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'course_id' => 'The course\'s id.',
                    'course' => 'The course\'s name.',
                    'quarter' => 'The quarter\'s number.',
                    'warm_up' => 'True for the warm-up quarters before students decide.',
                    'state' => 'locked, open, processing or closed.',
                    'deadline_at' => 'The decision deadline.',
                    'deadline_extended' => 'True when faculty extended the deadline.',
                    'opened_at' => 'When the quarter opened.',
                    'opened_after_deadline' => 'True when it opened after its own deadline and waits for a new one.',
                    'overdue' => 'True when the deadline has passed and the quarter is still open.',
                    'close_started_at' => 'When the close began.',
                    'closed_at' => 'When the close finished.',
                    'close_minutes' => 'Minutes from the start of the close to its end.',
                    'firms' => 'Firms in the course.',
                    'firms_submitted' => 'Firms whose team submitted decisions.',
                    'firms_carried_forward' => 'Firms that played on last quarter\'s decisions.',
                    'explanations_required' => 'True when teams explain each quarter before deciding.',
                    'explanations_submitted' => 'Team explanations of the quarter before, submitted.',
                ],
            ],
            'report_closes' => [
                'title' => 'Firm closes',
                'description' => 'One row per firm close in the last 90 days: its status, the error\'s short code, and how long it took.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'close_id' => 'The close\'s id.',
                    'course_id' => 'The course\'s id.',
                    'course' => 'The course\'s name.',
                    'quarter' => 'The quarter\'s number.',
                    'firm_id' => 'The firm\'s id.',
                    'status' => 'pending, processing, succeeded or failed.',
                    'error_code' => 'For a failed close: plausibility, decision, dividend, accounting or other.',
                    'started_at' => 'When the close started.',
                    'completed_at' => 'When it finished.',
                    'seconds' => 'Seconds it took.',
                ],
            ],
            'report_requests' => [
                'title' => 'Accounting-method requests',
                'description' => 'One row per accounting-method request: its status, when it was filed and decided, days waiting, and whether it carried a reason and Claude\'s review.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'request_id' => 'The request\'s id.',
                    'course_id' => 'The course\'s id.',
                    'course' => 'The course\'s name.',
                    'quarter' => 'The quarter it was filed in.',
                    'status' => 'pending, approved or declined.',
                    'filed_at' => 'When the team filed it.',
                    'decided_at' => 'When faculty decided it, else null.',
                    'days_waiting' => 'Days from filing to decision, or to now while it waits.',
                    'has_reason' => 'True when the team gave a reason.',
                    'review' => 'Claude\'s review: written, failed, pending, or null when none.',
                ],
            ],
            'report_ai_use' => [
                'title' => 'AI use',
                'description' => 'One row per course, month and AI feature in the last 12 months: calls, failures, sentences the checks dropped, tokens and cost.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'course_id' => 'The course\'s id.',
                    'course' => 'The course\'s name.',
                    'month' => 'The month, as YYYY-MM.',
                    'feature' => 'ask_claude, readings, request_reviews or debrief_captions.',
                    'calls' => 'Calls to Claude, each retry counted.',
                    'failures' => 'Calls that failed or came back unusable.',
                    'sentences_dropped' => 'Sentences the figure and advice checks removed.',
                    'input_tokens' => 'Input tokens.',
                    'output_tokens' => 'Output tokens.',
                    'cost_usd' => 'Cost in US dollars.',
                ],
            ],
            'report_health' => [
                'title' => 'Site health',
                'description' => 'One row for the site: the queue, failed jobs, the scheduler, migrations not yet run, versions, AI spend against its ceiling, and the certificate.',
                'columns' => [
                    'sim' => 'Which sim the row comes from.',
                    'contract' => 'The reporting contract\'s version.',
                    'checked_at' => 'When these figures were worked out.',
                    'environment' => 'The app environment.',
                    'commit' => 'The deployed commit, else null.',
                    'php_version' => 'PHP\'s version.',
                    'database_version' => 'The database server\'s version.',
                    'migrations_pending' => 'Migrations not yet run.',
                    'jobs_waiting' => 'Jobs in the queue.',
                    'oldest_job_minutes' => 'Minutes the oldest waiting job has waited, else null.',
                    'failed_jobs_7d' => 'Jobs that failed in the last seven days.',
                    'scheduler_last_run_at' => 'When the scheduler last ran, else null.',
                    'scheduler_ok' => 'True when the scheduler ran in the last five minutes.',
                    'courses_running' => 'Courses running now.',
                    'quarters_overdue' => 'Quarters past their deadline and still open.',
                    'closes_failed' => 'Firm closes failed and not yet retried.',
                    'requests_waiting' => 'Accounting-method requests waiting for faculty.',
                    'ai_enabled' => 'True when AI features can run on this site.',
                    'ai_spent_this_month_usd' => 'AI spend this month, in US dollars.',
                    'ai_ceiling_usd' => 'The site\'s monthly AI ceiling, in US dollars.',
                    'certificate_expires_at' => 'When the site\'s TLS certificate expires, else null.',
                    'certificate_days_left' => 'Days until it expires, else null.',
                    'connections_active' => 'Active reporting connections.',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function run(string $tool, array $args = []): array
    {
        return match ($tool) {
            'report_courses' => $this->courses(),
            'report_quarters' => $this->quarters(),
            'report_closes' => $this->closes(),
            'report_requests' => $this->requests(),
            'report_ai_use' => $this->aiUse(),
            'report_health' => [$this->health()],
        };
    }

    /** @return Collection<int, Course> */
    private function realCourses(): Collection
    {
        return Course::query()->real()->with('institution')->orderBy('created_at')->get();
    }

    private function sim(): string
    {
        return (string) config('flexee.reporting.sim');
    }

    private static function at(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @return list<array<string, mixed>> */
    public function courses(): array
    {
        $courses = $this->realCourses();
        $ids = $courses->pluck('id');
        $firms = DB::table('firms')->whereIn('course_id', $ids)->selectRaw('course_id, count(*) as n')->groupBy('course_id')->pluck('n', 'course_id');
        $students = DB::table('enrollments')->whereIn('course_id', $ids)->whereNull('deleted_at')
            ->selectRaw('course_id, count(*) as n, sum(case when paid_at is not null then 1 else 0 end) as paid')->groupBy('course_id')->get()->keyBy('course_id');

        return $courses->map(function (Course $course) use ($firms, $students) {
            $status = CourseStatus::for($course);
            $active = $status->active();

            return [
                'sim' => $this->sim(),
                'course_id' => $course->id,
                'course' => $course->name,
                'institution' => $course->institution?->name,
                'product' => Product::name($course),
                'engine_version' => $course->engineVersion(),
                'status' => $status->isFinished() ? 'finished' : ($course->status === 'deployed' ? 'running' : 'setting_up'),
                'quarter_now' => $active?->quarter_number,
                'quarters' => (int) $course->quarters()->max('quarter_number'),
                'firms' => (int) ($firms[$course->id] ?? 0),
                'students' => (int) ($students[$course->id]->n ?? 0),
                'students_confirmed' => (int) ($students[$course->id]->paid ?? 0),
                'next_deadline_at' => $active?->state === CourseQuarter::OPEN ? self::at($active->decision_deadline_at) : null,
                'created_at' => self::at($course->created_at),
                'deployed_at' => self::at($course->deployed_at),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function quarters(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $quarters = CourseQuarter::query()->whereIn('course_id', $courses->keys())->orderBy('course_id')->orderBy('quarter_number')->get();
        $firms = DB::table('firms')->whereIn('course_id', $courses->keys())->selectRaw('course_id, count(*) as n')->groupBy('course_id')->pluck('n', 'course_id');
        $decisions = DB::table('firm_quarter_decisions')->whereIn('course_quarter_id', $quarters->pluck('id'))
            ->selectRaw('course_quarter_id, sum(case when submitted_at is not null and carried_forward = ? then 1 else 0 end) as submitted, sum(case when carried_forward = ? then 1 else 0 end) as carried', [false, true])
            ->groupBy('course_quarter_id')->get()->keyBy('course_quarter_id');
        $explained = DB::table('firm_quarter_interpretations')->whereIn('course_quarter_id', $quarters->pluck('id'))->where('state', FirmQuarterInterpretation::SUBMITTED)
            ->selectRaw('course_quarter_id, count(*) as n')->groupBy('course_quarter_id')->pluck('n', 'course_quarter_id');
        $statuses = [];

        return $quarters->map(function (CourseQuarter $q) use ($courses, $firms, $decisions, $explained, &$statuses) {
            $course = $courses[$q->course_id];
            $status = $statuses[$course->id] ??= CourseStatus::for($course);
            $warm = $q->quarter_number <= 3;
            $close = $q->close_started_at && $q->closed_at ? round($q->close_started_at->diffInSeconds($q->closed_at) / 60, 1) : null;

            return [
                'sim' => $this->sim(),
                'course_id' => $course->id,
                'course' => $course->name,
                'quarter' => $q->quarter_number,
                'warm_up' => $warm,
                'state' => $q->state,
                'deadline_at' => self::at($q->decision_deadline_at),
                'deadline_extended' => $q->deadline_extended_at !== null,
                'opened_at' => self::at($q->opened_at),
                'opened_after_deadline' => $q->state === CourseQuarter::OPEN && QuarterScheduler::needsNewDeadline($q),
                'overdue' => $status->isOverdue($q),
                'close_started_at' => self::at($q->close_started_at),
                'closed_at' => self::at($q->closed_at),
                'close_minutes' => $close,
                'firms' => (int) ($firms[$course->id] ?? 0),
                'firms_submitted' => $warm ? null : (int) ($decisions[$q->id]->submitted ?? 0),
                'firms_carried_forward' => $warm ? null : (int) ($decisions[$q->id]->carried ?? 0),
                'explanations_required' => (bool) $course->interpretations_required,
                'explanations_submitted' => $warm ? null : (int) ($explained[$q->id] ?? 0),
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function closes(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $since = now()->subDays((int) config('flexee.reporting.closes_days', 90));

        return FirmQuarterClose::query()
            ->whereIn('course_id', $courses->keys())
            ->where('created_at', '>=', $since)
            ->with('quarter:id,quarter_number')
            ->orderBy('created_at')
            ->get()
            ->map(fn (FirmQuarterClose $c) => [
                'sim' => $this->sim(),
                'close_id' => $c->id,
                'course_id' => $c->course_id,
                'course' => $courses[$c->course_id]->name,
                'quarter' => $c->quarter?->quarter_number,
                'firm_id' => $c->firm_id,
                'status' => $c->status,
                'error_code' => $c->status === FirmQuarterClose::FAILED ? (string) (($c->safe_error['category'] ?? null) ?: 'other') : null,
                'started_at' => self::at($c->started_at),
                'completed_at' => self::at($c->completed_at),
                'seconds' => $c->started_at && $c->completed_at ? (int) $c->started_at->diffInSeconds($c->completed_at) : null,
            ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $reviews = AccountingRequestReview::query()->whereIn('course_id', $courses->keys())->pluck('status', 'request_id');

        return FirmAccountingElectionRequest::query()
            ->whereIn('course_id', $courses->keys())
            ->with('courseQuarter:id,quarter_number')
            ->orderBy('created_at')
            ->get()
            ->map(function (FirmAccountingElectionRequest $r) use ($courses, $reviews) {
                $end = $r->status === 'pending' ? now() : ($r->reviewed_at ?? $r->updated_at);

                return [
                    'sim' => $this->sim(),
                    'request_id' => $r->id,
                    'course_id' => $r->course_id,
                    'course' => $courses[$r->course_id]->name,
                    'quarter' => $r->courseQuarter?->quarter_number,
                    'status' => $r->status,
                    'filed_at' => self::at($r->created_at),
                    'decided_at' => $r->status === 'pending' ? null : self::at($r->reviewed_at),
                    'days_waiting' => round($r->created_at->diffInHours($end) / 24, 1),
                    'has_reason' => filled($r->justification),
                    'review' => $reviews[$r->id] ?? null,
                ];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function aiUse(): array
    {
        $courses = $this->realCourses()->keyBy('id');
        $since = now()->startOfMonth()->subMonths(11);
        $sources = [
            'ask_claude' => AiQuestion::query()->where('created_at', '>=', $since)->get(['course_id', 'created_at', 'status', 'dropped', 'input_tokens', 'output_tokens', 'cost_usd'])
                ->map(fn ($r) => [$r->course_id, $r->created_at, $r->status === AiQuestion::FAILED, $r->dropped, $r, 1]),
            'readings' => FirmQuarterReading::query()->where('updated_at', '>=', $since)->whereIn('status', [FirmQuarterReading::WRITTEN, FirmQuarterReading::FAILED])
                ->get(['course_id', 'written_at', 'updated_at', 'status', 'attempts', 'dropped', 'input_tokens', 'output_tokens', 'cost_usd'])
                ->map(fn ($r) => [$r->course_id, $r->written_at ?? $r->updated_at, $r->status === FirmQuarterReading::FAILED, $r->dropped, $r, (int) $r->attempts]),
            'request_reviews' => AccountingRequestReview::query()->where('updated_at', '>=', $since)->whereIn('status', [AccountingRequestReview::WRITTEN, AccountingRequestReview::FAILED])
                ->get(['course_id', 'written_at', 'updated_at', 'status', 'attempts', 'dropped', 'input_tokens', 'output_tokens', 'cost_usd'])
                ->map(fn ($r) => [$r->course_id, $r->written_at ?? $r->updated_at, $r->status === AccountingRequestReview::FAILED, $r->dropped, $r, (int) $r->attempts]),
            'debrief_captions' => CourseDebrief::query()->where('updated_at', '>=', $since)->where(fn ($q) => $q->whereNotNull('written_at')->orWhereNotNull('last_error'))
                ->get(['course_id', 'written_at', 'updated_at', 'last_error', 'dropped', 'input_tokens', 'output_tokens', 'cost_usd'])
                ->map(fn ($r) => [$r->course_id, $r->written_at ?? $r->updated_at, filled($r->last_error), $r->dropped, $r, 1]),
        ];
        $rows = [];
        foreach ($sources as $feature => $items) {
            // A reading or review is tried up to three times: each try is a call. One answered in code makes none.
            foreach ($items as [$courseId, $at, $failed, $dropped, $r, $tries]) {
                if (! isset($courses[$courseId])) {
                    continue;
                }
                $month = $at->copy()->utc()->format('Y-m');
                $key = "{$courseId}|{$month}|{$feature}";
                $rows[$key] ??= [
                    'sim' => $this->sim(), 'course_id' => $courseId, 'course' => $courses[$courseId]->name, 'month' => $month, 'feature' => $feature,
                    'calls' => 0, 'failures' => 0, 'sentences_dropped' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0,
                ];
                $rows[$key]['calls'] += $tries;
                $rows[$key]['failures'] += $failed ? $tries : max(0, $tries - 1);
                $rows[$key]['sentences_dropped'] += is_array($dropped) ? count($dropped) : 0;
                $rows[$key]['input_tokens'] += (int) $r->input_tokens;
                $rows[$key]['output_tokens'] += (int) $r->output_tokens;
                $rows[$key]['cost_usd'] += (float) $r->cost_usd;
            }
        }
        ksort($rows);

        return array_values(array_map(fn ($row) => ['cost_usd' => round($row['cost_usd'], 2)] + $row, $rows));
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        $courses = $this->realCourses();
        $ids = $courses->pluck('id');
        $oldest = DB::table('jobs')->min('available_at');
        $schedulerAt = Cache::get(self::SCHEDULER_KEY);
        $certificateAt = Cache::get(self::CERTIFICATE_KEY);
        $quarters = $this->quarters();

        return [
            'sim' => $this->sim(),
            'contract' => (string) config('flexee.reporting.contract'),
            'checked_at' => self::at(now()),
            'environment' => (string) config('app.env'),
            'commit' => self::commit(),
            'php_version' => PHP_VERSION,
            'database_version' => self::databaseVersion(),
            'migrations_pending' => self::migrationsPending(),
            'jobs_waiting' => DB::table('jobs')->count(),
            'oldest_job_minutes' => $oldest ? (int) floor((now()->getTimestamp() - (int) $oldest) / 60) : null,
            'failed_jobs_7d' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count(),
            'scheduler_last_run_at' => $schedulerAt ? self::at(now()->setTimestamp((int) $schedulerAt)) : null,
            'scheduler_ok' => $schedulerAt !== null && now()->getTimestamp() - (int) $schedulerAt <= 300,
            'courses_running' => collect($this->courses())->where('status', 'running')->count(),
            'quarters_overdue' => collect($quarters)->where('overdue', true)->count(),
            'closes_failed' => FirmQuarterClose::query()->whereIn('course_id', $ids)->where('status', FirmQuarterClose::FAILED)->count(),
            'requests_waiting' => FirmAccountingElectionRequest::query()->whereIn('course_id', $ids)->where('status', 'pending')->count(),
            'ai_enabled' => QuarterReadings::siteReady(),
            'ai_spent_this_month_usd' => round(AskClaude::spentThisMonth(), 2),
            'ai_ceiling_usd' => round((float) config('flexee.ai.monthly_spend_ceiling_usd'), 2),
            'certificate_expires_at' => $certificateAt ? self::at(now()->setTimestamp((int) $certificateAt)) : null,
            'certificate_days_left' => $certificateAt ? (int) floor(((int) $certificateAt - now()->getTimestamp()) / 86400) : null,
            'connections_active' => ReportingConnection::query()->whereNull('revoked_at')->where('refresh_expires_at', '>', now())->count(),
        ];
    }

    /** The commit the server's checkout is on (the deploy fast-forwards a git checkout). */
    public static function commit(): ?string
    {
        try {
            $git = base_path('.git');
            $head = trim((string) @file_get_contents($git.'/HEAD'));
            if (preg_match('/^[0-9a-f]{40}$/', $head)) {
                return substr($head, 0, 12);
            }
            if (! str_starts_with($head, 'ref: ')) {
                return null;
            }
            $ref = substr($head, 5);
            $sha = trim((string) @file_get_contents($git.'/'.$ref));
            if ($sha === '' && is_file($git.'/packed-refs')) {
                foreach (file($git.'/packed-refs') as $line) {
                    if (str_ends_with(trim($line), ' '.$ref)) {
                        $sha = strtok($line, ' ');
                    }
                }
            }

            return preg_match('/^[0-9a-f]{40}$/', (string) $sha) ? substr((string) $sha, 0, 12) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function databaseVersion(): ?string
    {
        try {
            $row = DB::selectOne('select version() as v');

            return mb_substr((string) $row->v, 0, 100);
        } catch (Throwable) {
            return null;
        }
    }

    /** Migrations in the folder this database runs from (MySQL or MariaDB: migrations-mysql) that haven't run. */
    public static function migrationsPending(): ?int
    {
        try {
            $mysql = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
            $folder = $mysql && is_dir(database_path('migrations-mysql')) ? 'migrations-mysql' : 'migrations';
            $files = collect(File::files(database_path($folder)))->map(fn ($f) => $f->getFilenameWithoutExtension());
            $ran = DB::table('migrations')->pluck('migration');

            return $files->diff($ran)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** Daily: when the site's own certificate expires, read from the certificate the site presents. */
    public static function checkCertificate(): ?int
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! $host || parse_url((string) config('app.url'), PHP_URL_SCHEME) !== 'https') {
            return null;
        }
        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
            $socket = @stream_socket_client("ssl://{$host}:443", $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
            if (! $socket) {
                return null;
            }
            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            fclose($socket);
            $info = $cert ? openssl_x509_parse($cert) : false;
            $expires = is_array($info) ? (int) ($info['validTo_time_t'] ?? 0) : 0;
            if ($expires > 0) {
                Cache::forever(self::CERTIFICATE_KEY, $expires);

                return $expires;
            }
        } catch (Throwable) {
        }

        return null;
    }
}
