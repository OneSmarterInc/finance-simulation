<?php

namespace App\Services\Ai;

use App\Models\AccountingRequestReview;
use App\Models\Course;
use App\Models\FirmAccountingElectionRequest;
use App\Models\FirmEngineResult;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Accounting\FirmAccountingElectionRequestService;
use App\Services\Accounting\FirmAccountingElectionService;
use App\Services\Courses\CourseStatus;
use App\Support\Presentation\AccountingMethodGuide;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Presentation\StatementPresenter;
use App\Support\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * U12 (decided 17 October 2026; RV1, 19 October 2026): Claude's review of
 * each accounting-method request. Written once, by the clock, after the
 * request is filed, and stored. A recommendation (approve, decline, or ask
 * the team for more), two to four reasons tied to the team's reason and the
 * standard tests for a change in accounting principle, and a draft reply
 * faculty can edit. Faculty only; faculty always decide.
 */
class RequestReviews
{
    public const MAX_ATTEMPTS = 3;

    /** Stored lines, beyond the common measures, that each method moves: method => [statement, line label]. */
    private const METHOD_LINES = [
        'depreciation_method' => [['cash_flow_statement', 'Depreciation'], ['balance_sheet', 'Property, plant and equipment, net']],
        'bad_debt_method' => [['income_statement', 'Bad debt expense'], ['balance_sheet', 'Accounts receivable']],
        'warranty_method' => [['income_statement', 'Warranty expense'], ['balance_sheet', 'Warranty reserve']],
        'shipping_terms' => [['balance_sheet', 'Inventory'], ['balance_sheet', 'Accounts payable']],
        'excise_tax_treatment' => [['income_statement', 'Gross profit'], ['income_statement', 'Excise duty included in cost']],
    ];

    private const COMMON = ['net_revenue', 'operating_income', 'net_income', 'cash'];

    /** The review is written and shown for this course. */
    public static function enabled(Course $course): bool
    {
        return QuarterReadings::siteReady() && (bool) $course->accounting_election_enabled
            && (bool) $course->ai_request_review && ! $course->is_practice;
    }

    public function tick(?int $limit = null): int
    {
        $limit ??= (int) config('flexee.ai.readings_per_run', 6);
        $done = 0;
        foreach ($this->due($limit) as $request) {
            if (AskClaude::spentThisMonth() >= (float) config('flexee.ai.monthly_spend_ceiling_usd')) {
                break;
            }
            if ($this->write($request)->isWritten()) {
                $done++;
            }
        }

        return $done;
    }

    /** @return Collection<int, FirmAccountingElectionRequest> requests waiting for faculty with no review yet, oldest first */
    public function due(int $limit): Collection
    {
        $courseIds = Course::query()->where('accounting_election_enabled', true)->get()->filter(fn (Course $c) => self::enabled($c))->pluck('id');
        if ($courseIds->isEmpty()) {
            return collect();
        }

        return FirmAccountingElectionRequest::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', FirmAccountingElectionRequestService::PENDING)
            ->whereDoesntHave('claudeReview', fn ($q) => $q->where(fn ($w) => $w->where('status', AccountingRequestReview::WRITTEN)
                ->orWhere('attempts', '>=', self::MAX_ATTEMPTS)
                ->orWhere('updated_at', '>', now()->subMinutes(2))))
            ->orderBy('created_at')->limit($limit)->get();
    }

    public function write(FirmAccountingElectionRequest $request): AccountingRequestReview
    {
        $request->loadMissing(['firm.course', 'courseQuarter']);
        $row = AccountingRequestReview::query()->firstOrNew(['request_id' => $request->id]);
        $row->course_id = $request->course_id;
        $current = $this->current($request);

        // Without a reason there is nothing to weigh (U12): ask for it, in code, with no call.
        if (blank($request->justification)) {
            $row->fill([
                'recommendation' => 'ask',
                'reasons' => ['The team gave no reason for the change, so there is nothing yet to weigh against the tests for a change in accounting principle.'],
                'reply' => FirmAccountingElectionRequestService::reasonNote($request, $current),
                'dropped' => null, 'status' => AccountingRequestReview::WRITTEN, 'model' => null,
                'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0, 'last_error' => null, 'written_at' => now(),
            ])->save();

            return $row;
        }

        $data = $this->sourceData($request, $current);
        $row->attempts = $row->attempts + 1;
        try {
            $response = $this->send($this->system($request->firm->course), $data);
            $use = collect($response['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'write_review');
            if (! $use) {
                throw new RuntimeException('The reply held no review.');
            }
            $input = (array) ($use['input'] ?? []);
            $recommendation = (string) ($input['recommendation'] ?? '');
            if (! array_key_exists($recommendation, AccountingRequestReview::RECOMMENDATIONS)) {
                throw new RuntimeException('The review had no recommendation.');
            }
            $quotable = [(string) $request->justification];
            $reasons = [];
            $dropped = [];
            foreach (array_slice((array) ($input['reasons'] ?? []), 0, 4) as $reason) {
                $check = FigureCheck::apply(trim((string) $reason), [$data], $quotable);
                foreach ($check['dropped'] as $drop) {
                    $dropped[] = ['part' => 'reason', 'sentence' => $drop['sentence'], 'figures' => $drop['figures']];
                }
                if (trim($check['answer']) !== '') {
                    $reasons[] = trim($check['answer']);
                }
            }
            $reply = FigureCheck::apply(trim((string) ($input['reply'] ?? '')), [$data], $quotable);
            foreach ($reply['dropped'] as $drop) {
                $dropped[] = ['part' => 'reply', 'sentence' => $drop['sentence'], 'figures' => $drop['figures']];
            }
            if ($reasons === []) {
                throw new RuntimeException('Every reason failed the figure check.');
            }
            $in = (int) ($response['usage']['input_tokens'] ?? 0) + (int) ($response['usage']['cache_read_input_tokens'] ?? 0) + (int) ($response['usage']['cache_creation_input_tokens'] ?? 0);
            $out = (int) ($response['usage']['output_tokens'] ?? 0);
            $row->fill([
                'recommendation' => $recommendation,
                'reasons' => $reasons,
                'reply' => trim($reply['answer']) !== '' ? trim($reply['answer']) : null,
                'dropped' => $dropped ?: null,
                'status' => AccountingRequestReview::WRITTEN,
                'model' => (string) config('flexee.ai.model'),
                'input_tokens' => $in,
                'output_tokens' => $out,
                'cost_usd' => round($in / 1e6 * (float) config('flexee.ai.price_input_per_mtok') + $out / 1e6 * (float) config('flexee.ai.price_output_per_mtok'), 4),
                'last_error' => null,
                'written_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('request_review_failed', ['request_id' => $request->id, 'error' => $e->getMessage()]);
            $row->fill(['status' => AccountingRequestReview::FAILED, 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
        }

        return $row;
    }

    /** What Claude is given: the change, the reason, the timing, the firm's history of requests and its recent results. */
    public function sourceData(FirmAccountingElectionRequest $request, array $current): string
    {
        $firm = $request->firm;
        $course = $firm->course;
        $status = CourseStatus::for($course);
        $filedIn = $request->courseQuarter?->quarter_number;
        $target = app(FirmAccountingElectionRequestService::class)->effectiveQuarter($request);
        $last = (int) $course->quarters()->max('quarter_number');

        $lines = ['THE REQUEST'];
        $lines[] = "Firm: {$firm->name}. Filed in Quarter {$filedIn} of a course that runs Quarters 1 to {$last} (Quarters 1 to 3 are the warm-up, run by the simulation).";
        foreach (AccountingChangeDisclosures::changes($current, (array) $request->proposed_elections) as $change) {
            $lines[] = "Change: {$change['label']}, from {$change['from']} to {$change['to']}.";
        }
        foreach ((array) $request->proposed_elections as $key => $value) {
            if (($current[$key] ?? null) !== $value) {
                $lines[] = '  Now: '.DecisionPresenter::valueLabel($key, $current[$key] ?? null).'. '.AccountingMethodGuide::effect($key, (string) ($current[$key] ?? ''));
                $lines[] = '  Requested: '.DecisionPresenter::valueLabel($key, $value).'. '.AccountingMethodGuide::effect($key, (string) $value);
            }
        }
        $lines[] = $target ? "If approved now, the change applies from Quarter {$target->quarter_number}." : 'No quarter is left for the change to apply to.';

        $lines[] = '';
        $lines[] = 'THE TEAM\'S REASON (the students\' own words):';
        $lines[] = '"'.trim((string) $request->justification).'"';

        $lines[] = '';
        $lines[] = 'THIS FIRM\'S EARLIER METHOD REQUESTS';
        $earlier = FirmAccountingElectionRequest::query()->where('firm_id', $firm->id)->whereKeyNot($request->id)
            ->where('created_at', '<=', $request->created_at)->with('courseQuarter')->orderBy('created_at')->get();
        if ($earlier->isEmpty()) {
            $lines[] = 'None. This is the firm\'s first request.';
        }
        foreach ($earlier as $old) {
            $changes = collect(AccountingChangeDisclosures::changes((array) $old->previous_elections, (array) $old->proposed_elections))
                ->map(fn ($c) => "{$c['label']} to {$c['to']}")->join('; ') ?: 'methods';
            $outcome = match ($old->status) {
                'approved' => 'approved, from Quarter '.$old->effective_quarter_number,
                'rejected' => 'declined',
                'returned' => 'sent back to the team',
                'withdrawn' => 'withdrawn by the team',
                default => 'waiting',
            };
            $lines[] = "Quarter {$old->courseQuarter?->quarter_number}: {$changes} ({$outcome}).";
        }

        $lines[] = '';
        if ($course->cf_plus_enabled) {
            $start = $status->evaluationStart();
            $lines[] = "EVALUATION STAGE: this is a Flexee Financial Accounting+ course. Rivals see each firm's published statements and place offers to buy a controlling stake from Quarter {$start}, for {$course->adversarial_quarters} quarters.";
        } else {
            $lines[] = 'EVALUATION STAGE: none. Rivals never value this firm.';
        }

        $results = FirmEngineResult::query()->where('firm_id', $firm->id)->where('quarter_number', '<=', max(3, (int) $filedIn))
            ->orderByDesc('quarter_number')->limit(3)->get()->sortBy('quarter_number')->values();
        if ($results->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'THE FIRM\'S STORED RESULTS, LAST THREE QUARTERS';
            $all = FirmEngineResult::query()->where('firm_id', $firm->id)->orderBy('quarter_number')->get();
            foreach (self::COMMON as $measure) {
                $lines[] = '  '.ResultMeasures::MEASURES[$measure][0].': '.$results->map(fn ($r) => 'Quarter '.$r->quarter_number.' '.ResultMeasures::format($measure, ResultMeasures::value($measure, $r, $all)))->join(' | ');
            }
            $columns = $results->mapWithKeys(fn ($r) => ['Quarter '.$r->quarter_number => $r])->all();
            foreach (array_keys(array_diff_assoc((array) $request->proposed_elections, $current)) as $method) {
                foreach (self::METHOD_LINES[$method] ?? [] as [$statement, $label]) {
                    $row = collect(StatementPresenter::rows($statement, $columns))->first(fn ($r) => $r['label'] === $label);
                    if ($row) {
                        $cells = [];
                        foreach (array_keys($columns) as $i => $heading) {
                            $cells[] = $heading.' '.StatementPresenter::format($row['values'][$i] ?? null, $row['format']);
                        }
                        $lines[] = '  '.$label.': '.implode(' | ', $cells);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @return array<string, string> the methods the request would change, as the faculty page shows them */
    public function current(FirmAccountingElectionRequest $request): array
    {
        $elections = app(FirmAccountingElectionService::class);
        $target = $request->status === FirmAccountingElectionRequestService::PENDING ? app(FirmAccountingElectionRequestService::class)->effectiveQuarter($request) : null;

        return $request->previous_elections
            ?? ($target ? $elections->effective($request->firm, $target) : $elections->effective($request->firm, $request->courseQuarter));
    }

    private function system(Course $course): string
    {
        $product = Product::name($course);
        $only = AnthropicRequest::only('write_review');

        return <<<TXT
        You review a team's request to change one of its firm's accounting methods in {$product}, a course simulation in reading financial statements. Each team of students runs a manufacturing firm. Your review is for the instructor only; the students never see it. The instructor always decides.

        Use the write_review tool:
        - recommendation: "approve", "decline" or "ask" (send the request back so the team can explain more and file again).
        - reasons: two to four short reasons, one or two sentences each. Tie them to the team's reason and to the standard tests for a change in accounting principle: is the new method preferable for how this firm operates; is it consistent with the firm's circumstances as the stored results show them; how often has the firm changed methods; and, on a course with an evaluation stage, does the timing look aimed at making the firm look better to rivals just before they value it.
        - reply: a draft note to the team, three or four plain sentences, written as the instructor. Say what the decision rests on and what a stronger reason would say. The instructor edits it before sending. If you recommend approving, write the note the instructor would send if they declined anyway.

        Rules:
        - Quote figures exactly as given, for example "\$21,613,457.97". Don't add, subtract or work out new figures. Any sentence with a figure that isn't in what you're given is removed.
        - The team's reason is the students' own words. Quote it in quotation marks; never state a figure from it as fact.
        - Judge the reasoning, not the students. Don't mention grades.
        - No headings, bold or lists inside a reason or the reply.

        {$only}
        TXT;
    }

    /** @return array<string, mixed> */
    private function send(string $system, string $data): array
    {
        // A web request needs room for one call to Claude; the console has no limit, and setting one there would cap the whole run.
        if (! app()->runningInConsole()) {
            @set_time_limit(90);
        }

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => (string) config('services.anthropic.version'),
        ])->timeout((int) config('flexee.ai.timeout_seconds', 55))->acceptJson()->post((string) config('services.anthropic.url'), [
            ...AnthropicRequest::base(),
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            ...AnthropicRequest::answerWith('write_review', 'Store the review of this request.', ['type' => 'object', 'properties' => [
                'recommendation' => ['type' => 'string', 'enum' => array_keys(AccountingRequestReview::RECOMMENDATIONS)],
                'reasons' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 2, 'maxItems' => 4],
                'reply' => ['type' => 'string'],
            ], 'required' => ['recommendation', 'reasons', 'reply']]),
            'messages' => [['role' => 'user', 'content' => "Here is the request.\n\n".$data]],
        ])->throw()->json();
    }
}
