<?php

namespace App\Services\Courses;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmQuarterClose;
use App\Models\FirmQuarterDecision;
use App\Support\Dates;
use Illuminate\Support\Collection;

/**
 * Where a course stands right now: the current quarter, its state and
 * deadline, which firms have submitted, and what needs the faculty
 * member's attention. Feeds the dashboard cards (items 15, 16), the course
 * sidebar (item 18), the quarters page (items 47, 49, 50) and the student
 * course card and dashboard (items 68, 70).
 */
final class CourseStatus
{
    /** @var Collection<int, CourseQuarter> */
    public readonly Collection $quarters;

    /** @var Collection<int, Firm> */
    public readonly Collection $firms;

    private ?Collection $decisionsCache = null;

    private function __construct(public readonly Course $course)
    {
        $this->quarters = $course->quarters()->orderBy('quarter_number')->get();
        $this->firms = $course->firms()->orderBy('code')->get();
    }

    public static function for(Course $course): self
    {
        return new self($course);
    }

    /** The open or processing quarter, else the next one to open, else null. */
    public function current(): ?CourseQuarter
    {
        return $this->active() ?? $this->next();
    }

    public function active(): ?CourseQuarter
    {
        return $this->quarters->first(
            fn (CourseQuarter $q) => $q->quarter_number >= 4 && in_array($q->state, [CourseQuarter::OPEN, CourseQuarter::PROCESSING], true),
        );
    }

    public function next(): ?CourseQuarter
    {
        return $this->quarters->first(fn (CourseQuarter $q) => $q->quarter_number >= 4 && $q->state === CourseQuarter::LOCKED);
    }

    public function lastClosed(): ?CourseQuarter
    {
        return $this->quarters->filter(fn (CourseQuarter $q) => $q->state === CourseQuarter::CLOSED)->last();
    }

    public function isFinished(): bool
    {
        $student = $this->quarters->where('quarter_number', '>=', 4);

        return $student->isNotEmpty() && $student->every(fn (CourseQuarter $q) => $q->state === CourseQuarter::CLOSED);
    }

    /** Plain words for a quarter's state (item 50). */
    public function stateLabel(CourseQuarter $quarter): string
    {
        return match (true) {
            $quarter->quarter_number <= 3 => 'Warm-up',
            $quarter->state === CourseQuarter::OPEN => 'Open',
            $quarter->state === CourseQuarter::PROCESSING => $this->failedCloses($quarter)->isNotEmpty() ? 'Needs a retry' : 'Calculating results',
            $quarter->state === CourseQuarter::CLOSED => 'Closed',
            default => 'Not open yet',
        };
    }

    public function stateColor(CourseQuarter $quarter): string
    {
        return match ($this->stateLabel($quarter)) {
            'Open' => 'emerald',
            'Calculating results' => 'amber',
            'Needs a retry' => 'red',
            'Closed' => 'zinc',
            'Warm-up' => 'sky',
            default => 'zinc',
        };
    }

    /** When a quarter that is not open yet will open, in words. */
    public function opensWhen(CourseQuarter $quarter): string
    {
        if ($quarter->quarter_number === 4) {
            return 'Opens automatically on '.Dates::day($quarter->starts_on).'.';
        }
        $previous = $this->quarters->firstWhere('quarter_number', $quarter->quarter_number - 1);

        return $previous
            ? 'Opens automatically when Quarter '.$previous->quarter_number.' closes after its deadline, '.Dates::dateTime($previous->decision_deadline_at).'.'
            : 'Opens automatically when the previous quarter closes.';
    }

    // ---- evaluation stage (Flexee Financial Accounting+) ------------------

    public function evaluationStart(): ?int
    {
        return $this->course->cf_plus_enabled ? 4 + (int) $this->course->build_quarters : null;
    }

    public function isEvaluationQuarter(CourseQuarter $quarter): bool
    {
        $start = $this->evaluationStart();

        return $start !== null && $quarter->quarter_number >= $start
            && $quarter->quarter_number < $start + (int) $this->course->adversarial_quarters;
    }

    public function evaluationQuarter(): ?CourseQuarter
    {
        $start = $this->evaluationStart();

        return $start === null ? null : $this->quarters->firstWhere('quarter_number', $start);
    }

    public function evaluationOpen(): bool
    {
        $start = $this->evaluationStart();

        return $start !== null && $this->quarters
            ->where('quarter_number', '>=', $start)
            ->contains(fn (CourseQuarter $q) => $q->state !== CourseQuarter::LOCKED);
    }

    /** "Opens with Quarter 6, around 3 Nov 2026" for screens before the stage opens. */
    public function evaluationOpensText(): ?string
    {
        $quarter = $this->evaluationQuarter();
        if (! $quarter) {
            return null;
        }
        $previous = $this->quarters->firstWhere('quarter_number', $quarter->quarter_number - 1);
        $when = $previous?->decision_deadline_at ?? $quarter->starts_on;

        return 'Opens with Quarter '.$quarter->quarter_number.', after '.Dates::dateTime($when).'.';
    }

    // ---- firms and submissions --------------------------------------------

    /** @return Collection<string, FirmQuarterDecision> keyed by firm id */
    public function decisions(CourseQuarter $quarter): Collection
    {
        $this->decisionsCache ??= collect();

        return $this->decisionsCache[$quarter->id] ??= FirmQuarterDecision::query()
            ->with('submittedBy.user')
            ->where('course_quarter_id', $quarter->id)
            ->get()
            ->keyBy('firm_id');
    }

    /**
     * @return array{state: string, label: string, decision: FirmQuarterDecision|null}
     *         state is submitted | draft | none | locked
     */
    public function firmStatus(Firm $firm, CourseQuarter $quarter): array
    {
        $decision = $this->decisions($quarter)->get($firm->id);

        if (! $decision) {
            return ['state' => 'none', 'label' => 'Not submitted yet', 'decision' => null];
        }

        return match ($decision->state) {
            FirmQuarterDecision::SUBMITTED => [
                'state' => 'submitted',
                'label' => 'Submitted'.($decision->submittedBy?->user ? ' by '.$decision->submittedBy->user->name : ''),
                'decision' => $decision,
            ],
            FirmQuarterDecision::LOCKED => [
                'state' => 'locked',
                'label' => $decision->carried_forward ? 'Carried forward, no submission' : 'Submitted',
                'decision' => $decision,
            ],
            default => ['state' => 'draft', 'label' => 'Draft saved, not submitted', 'decision' => $decision],
        };
    }

    /** @return Collection<int, Firm> firms with no submitted decision in the quarter */
    public function notSubmitted(CourseQuarter $quarter): Collection
    {
        return $this->firms->reject(
            fn (Firm $firm) => in_array($this->firmStatus($firm, $quarter)['state'], ['submitted', 'locked'], true),
        )->values();
    }

    public function submittedCount(CourseQuarter $quarter): int
    {
        return $this->firms->count() - $this->notSubmitted($quarter)->count();
    }

    /** @return Collection<int, FirmQuarterClose> */
    public function failedCloses(CourseQuarter $quarter): Collection
    {
        return FirmQuarterClose::query()
            ->with('firm')
            ->where('course_quarter_id', $quarter->id)
            ->where('status', FirmQuarterClose::FAILED)
            ->get();
    }

    public function pendingRequests(): int
    {
        return FirmAccountingElectionRequest::query()
            ->where('course_id', $this->course->id)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Short lines for the dashboard card (item 16).
     *
     * @return list<array{text: string, route: string}>
     */
    public function needsYou(): array
    {
        $items = [];
        $active = $this->active();

        if ($this->course->accounting_election_enabled && ($pending = $this->pendingRequests()) > 0) {
            $items[] = [
                'text' => $pending.' accounting-method '.str('request')->plural($pending).' waiting for you',
                'route' => route('faculty.courses.accounting-election-requests', $this->course),
            ];
        }

        if ($active && $active->state === CourseQuarter::PROCESSING && ($failed = $this->failedCloses($active)->count()) > 0) {
            $items[] = [
                'text' => $failed.' '.str('firm')->plural($failed).' in Quarter '.$active->quarter_number.' need a retry',
                'route' => route('faculty.courses.quarters', $this->course),
            ];
        }

        if ($active && $active->state === CourseQuarter::OPEN && ($missing = $this->notSubmitted($active))->isNotEmpty()) {
            $items[] = [
                'text' => $missing->count().' of '.$this->firms->count().' firms not submitted ('.$missing->pluck('name')->join(', ').')',
                'route' => route('faculty.courses.quarters', $this->course),
            ];
        }

        $evaluation = $this->evaluationQuarter();
        if ($evaluation && $evaluation->state === CourseQuarter::LOCKED) {
            $previous = $this->quarters->firstWhere('quarter_number', $evaluation->quarter_number - 1);
            $when = $previous?->decision_deadline_at;
            if ($when && $when->isFuture() && $when->diffInDays(now(), true) <= 7) {
                $items[] = [
                    'text' => 'The evaluation stage opens '.Dates::dateTime($when).': firms freeze and statements become visible to rivals',
                    'route' => route('faculty.courses.quarters', $this->course),
                ];
            }
        }

        if ($this->course->cf_plus_enabled && $this->isFinished() && ! $this->course->cfPlusAuctionResults()->exists()) {
            $items[] = [
                'text' => 'All quarters are closed. Settle the evaluation stage to show students the reveal',
                'route' => route('faculty.courses.cf-plus-auction', $this->course),
            ];
        }

        return $items;
    }
}
