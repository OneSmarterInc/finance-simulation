<?php

namespace App\Services\Ai;

use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterInterpretation;
use App\Models\FirmQuarterReading;
use App\Services\Accounting\AccountingChangeDisclosures;
use App\Services\Decisions\FirmDecisionHistory;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Interpretations\InterpretationService;
use App\Support\Presentation\DecisionPresenter;
use App\Support\Presentation\StatementPresenter;
use App\Support\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Live walkthrough L15 (item 53 with N6, AI1 a; U11 layer 2, AR1 and AR2):
 * Claude's reading of each firm's quarter, a short "What this shows"
 * paragraph under each report.
 *
 * - Written once, after the quarter closes, by the clock (flexee:readings),
 *   never inside the close, and stored: everyone sees the same words.
 * - Claude is given this quarter and the one before, the decisions that
 *   produced it and the methods in force, and nothing else.
 * - Every paragraph passes two checks in code before it's stored: the figure
 *   check (U8) and the advice check. It describes; it never advises.
 * - Faculty switches: "Show to me" (on) and "Show to students" (off). A
 *   practice run follows its source course's "Show to me" (AR2).
 * - Students see a quarter's reading once their team has submitted its
 *   explanation of it, or once that explanation's deadline has passed (AR1).
 */
class QuarterReadings
{
    /** report tab => label, in the order the tabs run */
    public const SECTIONS = [
        'summary' => 'Summary',
        'income_statement' => 'Income statement',
        'balance_sheet' => 'Balance sheet',
        'cash_flow_statement' => 'Cash flow',
        'notes' => 'Notes',
    ];

    public const MAX_ATTEMPTS = 3;

    /** Part of source_hash: bump when the instructions change. */
    private const PROMPT_VERSION = 'reading-v1';

    public function __construct(
        private readonly FirmDecisionHistory $history,
        private readonly FirmDecisionService $decisions,
        private readonly InterpretationService $interpretations,
    ) {}

    public static function siteReady(): bool
    {
        return (bool) config('flexee.ai.enabled') && filled(config('services.anthropic.key'));
    }

    /** The course whose switches decide. A practice run follows its source course (AR2). */
    public static function switchesOf(Course $course): Course
    {
        if ($course->is_practice && $course->practice_source_course_id) {
            return Course::withTrashed()->find($course->practice_source_course_id) ?? $course;
        }

        return $course;
    }

    /** Readings are written for this course: nothing is written while both switches are off. */
    public static function writing(Course $course): bool
    {
        if (! self::siteReady()) {
            return false;
        }
        $switches = self::switchesOf($course);

        return $course->is_practice
            ? (bool) $switches->ai_reading_faculty
            : (bool) ($course->ai_reading_faculty || $course->ai_reading_students);
    }

    public static function showToFaculty(Course $course): bool
    {
        return self::siteReady() && (bool) self::switchesOf($course)->ai_reading_faculty;
    }

    public static function showToStudents(Course $course): bool
    {
        return self::siteReady() && ! $course->is_practice && (bool) $course->ai_reading_students;
    }

    /** Write the readings that are missing, a few at a time. @return int how many were written */
    public function tick(?int $limit = null): int
    {
        $limit ??= (int) config('flexee.ai.readings_per_run', 6);
        $started = microtime(true);
        $done = 0;
        foreach ($this->due($limit) as $result) {
            if (AskClaude::spentThisMonth() >= (float) config('flexee.ai.monthly_spend_ceiling_usd')) {
                break;
            }
            if (microtime(true) - $started > 40) {
                break;
            }
            if ($this->write($result)->isWritten()) {
                $done++;
            }
        }

        return $done;
    }

    /** @return Collection<int, FirmEngineResult> stored results still waiting for a reading, oldest first */
    public function due(int $limit)
    {
        $courseIds = Course::query()->whereHas('firms')->get()->filter(fn (Course $c) => self::writing($c))->pluck('id');
        if ($courseIds->isEmpty()) {
            return collect();
        }

        return FirmEngineResult::query()
            ->whereIn('course_id', $courseIds)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))->from('firm_quarter_readings as r')
                    ->whereColumn('r.firm_id', 'firm_engine_results.firm_id')
                    ->whereColumn('r.quarter_number', 'firm_engine_results.quarter_number')
                    ->where(fn ($q) => $q->where('r.status', FirmQuarterReading::WRITTEN)
                        ->orWhere('r.attempts', '>=', self::MAX_ATTEMPTS)
                        ->orWhere('r.updated_at', '>', now()->subMinutes(2)));
            })
            ->orderBy('quarter_number')->orderBy('created_at')
            ->limit($limit)->get();
    }

    public function write(FirmEngineResult $result): FirmQuarterReading
    {
        $firm = $result->firm()->with('course')->firstOrFail();
        $previous = FirmEngineResult::query()->where('firm_id', $firm->id)->where('quarter_number', $result->quarter_number - 1)->first();
        $data = $this->sourceData($firm, $result, $previous);
        $hash = hash('sha256', self::PROMPT_VERSION."\n".$data);

        $row = FirmQuarterReading::query()->firstOrNew(['firm_id' => $firm->id, 'quarter_number' => $result->quarter_number]);
        $row->fill(['course_id' => $firm->course_id, 'firm_engine_result_id' => $result->id, 'source_hash' => $hash]);

        $row->attempts = $row->attempts + 1;
        try {
            $response = $this->send($this->system($firm->course), $data);
            $use = collect($response['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'write_reading');
            if (! $use) {
                throw new RuntimeException('The reply held no reading.');
            }
            [$sections, $dropped] = $this->check((array) ($use['input'] ?? []), $data);
            if (array_filter($sections) === []) {
                throw new RuntimeException('Every paragraph failed the checks.');
            }
            $in = (int) ($response['usage']['input_tokens'] ?? 0) + (int) ($response['usage']['cache_read_input_tokens'] ?? 0) + (int) ($response['usage']['cache_creation_input_tokens'] ?? 0);
            $out = (int) ($response['usage']['output_tokens'] ?? 0);
            $row->fill([
                'sections' => $sections,
                'dropped' => $dropped ?: null,
                'status' => FirmQuarterReading::WRITTEN,
                'model' => (string) config('flexee.ai.model'),
                'input_tokens' => $in,
                'output_tokens' => $out,
                'cost_usd' => round($in / 1e6 * (float) config('flexee.ai.price_input_per_mtok') + $out / 1e6 * (float) config('flexee.ai.price_output_per_mtok'), 4),
                'last_error' => null,
                'written_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('quarter_reading_failed', ['firm_id' => $firm->id, 'quarter' => $result->quarter_number, 'error' => $e->getMessage()]);
            $row->fill(['status' => FirmQuarterReading::FAILED, 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
        }

        return $row;
    }

    /**
     * Both checks, paragraph by paragraph: advice first, then figures.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string|null>, 1: list<array{section: string, sentence: string, reason: string}>}
     */
    public function check(array $input, string $data): array
    {
        $sections = [];
        $dropped = [];
        foreach (array_keys(self::SECTIONS) as $key) {
            $advice = AdviceCheck::apply(trim((string) ($input[$key] ?? '')));
            foreach ($advice['dropped'] as $sentence) {
                $dropped[] = ['section' => $key, 'sentence' => $sentence, 'reason' => 'It told the team what to do.'];
            }
            $figures = FigureCheck::apply($advice['text'], [$data]);
            foreach ($figures['dropped'] as $drop) {
                $dropped[] = ['section' => $key, 'sentence' => $drop['sentence'], 'reason' => 'It quoted '.implode(', ', $drop['figures']).', which isn\'t in the stored results.'];
            }
            $sections[$key] = trim($figures['answer']) !== '' ? trim($figures['answer']) : null;
        }

        return [$sections, $dropped];
    }

    /**
     * What Claude is given: this quarter beside the one before, every report as the screens show
     * it, the methods changed this quarter and the decisions that produced it. Nothing else.
     */
    public function sourceData(Firm $firm, FirmEngineResult $result, ?FirmEngineResult $previous): string
    {
        $n = $result->quarter_number;
        $warmUp = $result->provenance === FirmEngineResult::PROVENANCE_WARMUP;
        $columns = $previous ? ['Quarter '.($n - 1) => $previous, 'Quarter '.$n => $result] : ['Quarter '.$n => $result];

        $lines = [];
        $lines[] = "THE QUARTER TO READ: Quarter {$n}".($warmUp
            ? ', a warm-up quarter, run by the simulation before the team took over the firm.'
            : ', decided by the team.');
        $lines[] = $previous ? 'COMPARED WITH: Quarter '.($n - 1).'.' : 'This is the firm\'s first quarter, so there is no quarter before it.';

        $blocks = ['summary' => 'SUMMARY'] + array_map('strtoupper', StatementPresenter::STATEMENTS);
        foreach ($blocks as $statement => $heading) {
            $lines[] = '';
            $lines[] = $heading;
            $lines = [...$lines, ...$this->table($statement, $columns)];
        }
        $lines[] = '';
        $lines[] = 'NOTES';
        foreach (StatementPresenter::NOTES as $note => $label) {
            $table = $this->table($note, $columns);
            if ($table !== []) {
                $lines[] = "Note: {$label}";
                $lines = [...$lines, ...$table];
            }
        }

        $changes = app(AccountingChangeDisclosures::class)->forQuarter($firm, $n);
        if ($changes !== []) {
            $lines[] = '';
            $lines[] = 'CHANGES IN ACCOUNTING METHODS FROM THIS QUARTER';
            foreach ($changes as $text) {
                // The team's reason is its own words, and stays out.
                $lines[] = preg_replace('/ The team\'s reason: ".*"$/s', '', str_replace($firm->name, 'The firm', $text));
            }
        }

        $entry = $this->history->forQuarter($firm, $n);
        $rows = DecisionPresenter::rows(['Quarter '.$n => $entry], $this->decisions->parameters($firm));
        if ($rows !== []) {
            $lines[] = '';
            $lines[] = "THE DECISIONS THAT PRODUCED QUARTER {$n}".($entry['carried_forward'] ?? false ? ' (the team did not submit, so the quarter before\'s decisions were carried forward)' : '');
            foreach ($rows as $row) {
                $lines[] = $row['type'] === 'heading'
                    ? $row['label'].' (chosen by: '.(($row['values'][0] ?? '') ?: 'the simulation').')'
                    : '  '.$row['label'].': '.($row['values'][0] ?? '');
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, FirmEngineResult> $columns @return list<string> */
    private function table(string $statement, array $columns): array
    {
        $out = [];
        foreach (StatementPresenter::rows($statement, $columns) as $row) {
            if ($row['type'] === 'heading') {
                $out[] = '  '.$row['label'];

                continue;
            }
            $values = $row['values'];
            if (! collect($values)->contains(fn ($v) => $v !== null && ($row['format'] === 'units' || abs((float) $v) >= 0.005)) && ! in_array($row['type'], ['subtotal', 'total'], true)) {
                continue;
            }
            $cells = [];
            foreach (array_keys($columns) as $i => $heading) {
                $cells[] = $heading.' '.StatementPresenter::format($values[$i] ?? null, $row['format']);
            }
            $out[] = '  '.$row['label'].': '.implode(' | ', $cells);
        }

        return count($out) > 0 && collect($out)->contains(fn ($l) => str_contains($l, ':')) ? $out : [];
    }

    private function system(Course $course): string
    {
        $product = Product::name($course);

        return <<<TXT
        You write the short reading of one quarter's financial statements for a firm in {$product}, a course simulation in reading financial statements. A team of students runs a manufacturing firm; each quarter brings an income statement, a balance sheet, a cash flow statement and notes. Your reading appears under each report on the firm's History page, headed "What this shows". Students read it after writing their own explanation of the quarter, to compare with theirs; their instructor reads it too.

        Write five short paragraphs with the write_reading tool, one for each report: the summary, the income statement, the balance sheet, the cash flow statement and the notes.

        - Each paragraph is two to four plain sentences about that report: which lines moved most since the quarter before, and what caused each move (one of the decisions, customers' orders, or an accounting method), using only what you are given. For the first quarter, say where the firm stands instead.
        - The notes paragraph names something the notes show that the three statements don't show on their own.
        - Quote figures exactly as given, for example "\$21,613,457.97", "18.4%" or "3.49x". Don't add, subtract, round or work out new figures, and don't give a change as an amount or a percentage: describe its size in words ("more than doubled", "fell slightly"). Any sentence with a figure that isn't in what you're given is removed.
        - Describe what happened. Never tell the team what to do, what to try, or what to change next quarter, and don't judge their choices as good or bad. Any sentence that gives advice is removed.
        - Call the firm "the firm". Don't name people.
        - No headings, bullet points, bold or lists. Short sentences, one idea each, in words a first-year student knows.
        TXT;
    }

    /** @return array<string, mixed> */
    private function send(string $system, string $data): array
    {
        @set_time_limit(90);
        $properties = [];
        foreach (self::SECTIONS as $key => $label) {
            $properties[$key] = ['type' => 'string', 'description' => "What the {$label} tab shows for this quarter: two to four sentences."];
        }

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => (string) config('services.anthropic.version'),
        ])->timeout((int) config('flexee.ai.timeout_seconds', 55))->acceptJson()->post((string) config('services.anthropic.url'), [
            'model' => config('flexee.ai.model'),
            'max_tokens' => (int) config('flexee.ai.max_tokens', 1500),
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => [[
                'name' => 'write_reading',
                'description' => 'Store the reading of this quarter, one short paragraph per report.',
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys(self::SECTIONS)],
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'write_reading'],
            'messages' => [['role' => 'user', 'content' => "Here is the firm's quarter.\n\n".$data]],
        ])->throw()->json();
    }

    /**
     * What faculty see under each tab ("Show to me").
     *
     * @param  array<string, FirmEngineResult>  $columns  column heading => result
     * @return array{audience: string, entries: array<string, array<string, mixed>>}|null null when the reading is off
     */
    public function forFaculty(Course $course, array $columns): ?array
    {
        if (! self::showToFaculty($course) || $columns === []) {
            return null;
        }
        $readings = $this->stored($columns);
        $entries = [];
        foreach ($columns as $label => $result) {
            $entries[$label] = $this->entry($result, $readings);
        }

        return ['audience' => 'faculty', 'entries' => $entries];
    }

    /**
     * What a team sees under each tab ("Show to students"), with each quarter held back
     * until the team has explained it or the explanation's deadline has passed (AR1).
     *
     * @param  array<string, FirmEngineResult>  $columns
     * @return array{audience: string, entries: array<string, array<string, mixed>>}|null
     */
    public function forStudents(Firm $firm, array $columns): ?array
    {
        if (! self::showToStudents($firm->course) || $columns === []) {
            return null;
        }
        $readings = $this->stored($columns);
        $entries = [];
        foreach ($columns as $label => $result) {
            $n = $result->quarter_number;
            $entries[$label] = $this->studentsMaySee($firm, $n)
                ? $this->entry($result, $readings)
                : ['state' => 'after_explanation', 'quarter' => $n, 'message' => "Claude's reading of Quarter {$n} appears here once your team submits its explanation of Quarter {$n}, or once the deadline for it passes."];
        }

        return ['audience' => 'students', 'entries' => $entries];
    }

    /** AR1: explained, or the explanation's window has closed, or no explanation is asked for this quarter. */
    public function studentsMaySee(Firm $firm, int $quarterNumber): bool
    {
        $course = $firm->course;
        if (! $this->interpretations->required($course)) {
            return true;
        }
        $explained = CourseQuarter::query()->where('course_id', $course->id)->where('quarter_number', $quarterNumber)->first();
        $next = CourseQuarter::query()->where('course_id', $course->id)->where('quarter_number', $quarterNumber + 1)->first();
        if (! $explained || ! $next || $next->quarter_number < 4) {
            return true;
        }
        $row = FirmQuarterInterpretation::query()->where('firm_id', $firm->id)->where('course_quarter_id', $explained->id)->first();
        if ($row?->isSubmitted()) {
            return true;
        }

        return in_array($next->state, [CourseQuarter::PROCESSING, CourseQuarter::CLOSED], true)
            || ($next->state === CourseQuarter::OPEN && ! $this->interpretations->editable($next));
    }

    /** @param array<string, FirmEngineResult> $columns @return \Illuminate\Support\Collection<string, FirmQuarterReading> */
    private function stored(array $columns)
    {
        $results = collect($columns);

        return FirmQuarterReading::query()
            ->whereIn('firm_id', $results->pluck('firm_id')->unique())
            ->whereIn('quarter_number', $results->pluck('quarter_number')->unique())
            ->get()->keyBy(fn ($r) => $r->firm_id.'|'.$r->quarter_number);
    }

    /** @param Collection<string, FirmQuarterReading> $readings @return array<string, mixed> */
    private function entry(FirmEngineResult $result, $readings): array
    {
        $n = $result->quarter_number;
        $row = $readings->get($result->firm_id.'|'.$n);
        if ($row?->isWritten()) {
            return ['state' => 'written', 'quarter' => $n, 'sections' => (array) $row->sections, 'dropped' => count((array) $row->dropped)];
        }
        if ($row && $row->attempts >= self::MAX_ATTEMPTS) {
            return ['state' => 'failed', 'quarter' => $n, 'message' => "Claude's reading of Quarter {$n} couldn't be written, so there's none for this quarter."];
        }

        return ['state' => 'waiting', 'quarter' => $n, 'message' => "Claude is writing its reading of Quarter {$n}. It appears here within a few minutes."];
    }
}
