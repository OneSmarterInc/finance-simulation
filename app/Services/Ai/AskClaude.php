<?php

namespace App\Services\Ai;

use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\Faculty;
use App\Support\Product;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ask Claude on faculty pages (U1, design note AC1 to AC8).
 *
 * One question runs here, during the request (AC4): Claude reads the guide
 * and the page it was asked from, looks up what it needs through
 * AskClaudeTools (read-only, this course only), and answers. The answer then
 * goes through the figure check (U8) before anyone sees it, and the whole
 * exchange is logged (AC7). Students reach Claude only as "Student n" (AC3).
 */
class AskClaude
{
    /**
     * @param  array{page: string, label: string, details?: array<string, string>}  $context  what the faculty member is looking at
     * @param  list<array{question: string, answer: string}>  $history  earlier turns on this course, oldest first
     * @return array{record: AiQuestion, answer: string, dropped: list<array{sentence: string, figures: list<string>}>, lookups: list<array{name: string, summary: string}>, checked: int, names: array<string, string>}
     */
    public function ask(Faculty $faculty, Course $course, array $context, string $question, array $history = []): array
    {
        $question = trim($question);
        $this->guard($faculty, $course, $question);

        $tools = new AskClaudeTools($course);
        $guide = $this->guide();
        $messages = [];
        foreach (array_slice($history, -4) as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['question']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['answer']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $lookups = [];
        $sources = [$guide, $question, json_encode($context)];
        $inputTokens = $outputTokens = 0;
        $answer = '';
        $status = AiQuestion::FAILED;

        try {
            for ($round = 0; $round <= (int) config('flexee.ai.max_lookup_rounds', 4); $round++) {
                $response = $this->send($this->system($course, $guide), $this->page($course, $context), $tools->definitions(), $messages);
                $inputTokens += (int) ($response['usage']['input_tokens'] ?? 0) + (int) ($response['usage']['cache_read_input_tokens'] ?? 0) + (int) ($response['usage']['cache_creation_input_tokens'] ?? 0);
                $outputTokens += (int) ($response['usage']['output_tokens'] ?? 0);
                $content = $response['content'] ?? [];
                $uses = array_values(array_filter($content, fn ($block) => ($block['type'] ?? '') === 'tool_use'));
                if ($uses === [] || ($response['stop_reason'] ?? '') !== 'tool_use') {
                    $answer = trim(implode("\n\n", array_map(fn ($b) => $b['text'], array_filter($content, fn ($b) => ($b['type'] ?? '') === 'text'))));
                    $status = AiQuestion::ANSWERED;
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $results = [];
                foreach ($uses as $use) {
                    $result = $tools->run((string) $use['name'], (array) ($use['input'] ?? []));
                    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $sources[] = $json;
                    $lookups[] = ['name' => (string) $use['name'], 'summary' => $this->summary((string) $use['name'], $result)];
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $json];
                }
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (ConnectionException|RequestException $e) {
            Log::warning('ask_claude_failed', ['course_id' => $course->id, 'error' => $e->getMessage()]);
        }

        $check = $status === AiQuestion::ANSWERED && $answer !== ''
            ? FigureCheck::apply($answer, $sources)
            : ['answer' => '', 'dropped' => [], 'checked' => 0];
        if ($status === AiQuestion::ANSWERED && $check['answer'] === '') {
            $check['answer'] = 'Every sentence of this answer quoted a figure the lookups did not return, so none of it is shown. Try asking in a different way.';
        }

        $record = AiQuestion::query()->create([
            'course_id' => $course->id,
            'faculty_id' => $faculty->id,
            'page' => $context['page'],
            'context' => $context,
            'question' => $question,
            'answer' => $status === AiQuestion::ANSWERED ? $check['answer'] : null,
            'lookups' => $lookups,
            'dropped' => $check['dropped'] ?: null,
            'status' => $status,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_usd' => round($inputTokens / 1e6 * (float) config('flexee.ai.price_input_per_mtok') + $outputTokens / 1e6 * (float) config('flexee.ai.price_output_per_mtok'), 4),
        ]);

        if ($status !== AiQuestion::ANSWERED) {
            throw ValidationException::withMessages(['question' => 'Claude couldn\'t be reached just now. Please try again in a minute.']);
        }

        return ['record' => $record, 'answer' => $check['answer'], 'dropped' => $check['dropped'], 'lookups' => $lookups, 'checked' => $check['checked'], 'names' => $tools->names(), 'views' => $tools->views()];
    }

    /** Ask Claude is available on this course for this faculty member: the site switch, the key and the course switch. */
    public static function available(Course $course): bool
    {
        return (bool) config('flexee.ai.enabled') && filled(config('services.anthropic.key')) && ! $course->is_practice;
    }

    public function questionsLeft(Course $course): int
    {
        $allowance = $course->ai_questions_per_month ?? (int) config('flexee.ai.questions_per_course_month', 100);

        return max(0, $allowance - AiQuestion::query()->where('course_id', $course->id)
            ->where('created_at', '>=', now()->startOfMonth())->where('status', AiQuestion::ANSWERED)->count());
    }

    private function guard(Faculty $faculty, Course $course, string $question): void
    {
        $teaches = $course->faculty_id === $faculty->id || $course->facultyRoles()->where('faculty_id', $faculty->id)->exists();
        abort_unless($teaches && ! $course->trashed(), 403);
        if (! self::available($course)) {
            throw ValidationException::withMessages(['question' => 'Ask Claude isn\'t switched on for this site yet.']);
        }
        if (! $course->ai_helper_enabled) {
            throw ValidationException::withMessages(['question' => 'Ask Claude is off for this course. Turn it on with the switch above.']);
        }
        if ($question === '' || mb_strlen($question) > 1000) {
            throw ValidationException::withMessages(['question' => 'Ask a question of up to 1,000 characters.']);
        }
        if ($this->questionsLeft($course) === 0) {
            throw ValidationException::withMessages(['question' => 'This course has used all its questions for this month. They start again on the 1st.']);
        }
        $spent = (float) AiQuestion::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
        if ($spent >= (float) config('flexee.ai.monthly_spend_ceiling_usd')) {
            throw ValidationException::withMessages(['question' => 'Ask Claude has reached this month\'s limit for the whole site. It will be back on the 1st.']);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, mixed>
     */
    private function send(string $system, string $page, array $tools, array $messages): array
    {
        @set_time_limit(90);

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => (string) config('services.anthropic.version'),
        ])->timeout((int) config('flexee.ai.timeout_seconds', 55))->acceptJson()->post((string) config('services.anthropic.url'), [
            'model' => config('flexee.ai.model'),
            'max_tokens' => (int) config('flexee.ai.max_tokens', 1500),
            // The rules and the guide are the same on every page, so they're cached; the page line isn't.
            'system' => [
                ['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']],
                ['type' => 'text', 'text' => $page],
            ],
            'tools' => $tools,
            'messages' => $messages,
        ])->throw()->json();
    }

    private function system(Course $course, string $guide): string
    {
        return <<<TXT
        You are Ask Claude, the helper on the instructor's pages of {$this->product($course)}, a course simulation in reading financial statements. Each team of students runs a manufacturing firm; every quarter brings a new income statement, balance sheet, cash flow statement and notes to interpret. The operating decisions exist only to produce those statements.

        How to answer:
        - Answer the instructor's question in a few short, plain sentences. No headings, no bold, and no bullet lists unless they ask for a list.
        - Use the lookups for anything about this course. For results, read them with get_results; when figures matter to the answer, call show_table once so the instructor sees them beside it.
        - Quote figures exactly as the lookups give them (for example "$21,613,457.97", "18.4%", "3.49x"); don't round, add up or work out new figures. Describe a change in words ("cash fell", "more than doubled") rather than computing it. Never guess a date, a deadline, a count, a status or any other figure: if a lookup or the guide didn't give it to you, don't state it. Any sentence with a figure that no lookup or the guide gave you is removed before the instructor sees it.
        - You can only read. If the instructor wants something done (extend a deadline, approve a request), say which page does it.
        - Students appear in lookups as Student 1, Student 2 and so on. Refer to them that way; the page shows their names.
        - Describe what happened and what the course will do. Don't tell the instructor how to grade, and don't suggest decisions for students.
        - If the guide and the lookups don't answer the question, say so plainly.

        The guide for instructors:
        {$guide}
        TXT;
    }

    /** @param array{page: string, label: string, details?: array<string, string>} $context */
    private function page(Course $course, array $context): string
    {
        $details = collect($context['details'] ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode('; ');

        return "The instructor is on the \"{$context['label']}\" page of the course \"{$course->name}\".".$this->detailLine($details);
    }

    private function detailLine(string $details): string
    {
        return $details === '' ? '' : " On screen: {$details}.";
    }

    private function product(Course $course): string
    {
        return Product::name($course);
    }

    private function guide(): string
    {
        return collect(['faculty-guide', 'one-pager', 'one-pager-plus'])
            ->map(fn ($name) => (string) file_get_contents(resource_path("guides/{$name}.md")))
            ->implode("\n\n---\n\n");
    }

    /** @param array<string, mixed> $result */
    private function summary(string $name, array $result): string
    {
        return match ($name) {
            'get_course_setup' => count($result['quarters'] ?? []).' quarters, the decision areas by quarter and the starting methods',
            'get_course_status' => ($result['open_quarter'] ?? null)
                ? 'Quarter '.$result['open_quarter']['quarter'].' open, and the submission status of '.count($result['open_quarter']['firms']).' '.Str::plural('firm', count($result['open_quarter']['firms']))
                : 'no quarter open; '.(($result['last_closed_quarter'] ?? null) ? 'last closed: Quarter '.$result['last_closed_quarter'] : 'warm-up only'),
            'get_results', 'show_table' => count($result['results'] ?? []).' '.Str::plural('firm', count($result['results'] ?? [])).'\' stored results'.($name === 'show_table' ? ', shown as a table' : ''),
            'get_decisions' => is_array($result['decisions'] ?? null) ? ($result['firm'] ?? '').'\'s Quarter '.($result['quarter'] ?? '').' decisions' : 'no calculated decisions for that quarter',
            'get_method_requests' => count($result['requests'] ?? []).' method '.Str::plural('request', count($result['requests'] ?? [])),
            'get_evaluation_stage' => ($result['evaluation_stage'] ?? null) === 'none' ? 'no evaluation stage in this course' : 'the evaluation stage, '.(($result['settled'] ?? false) ? 'settled' : (($result['open'] ?? false) ? 'open' : 'not open yet')),
            'get_practice_scoreboard' => isset($result['played_to_quarter']) ? 'the practice run, played to Quarter '.$result['played_to_quarter'] : 'no practice run',
            default => $result['error'] ?? 'nothing',
        };
    }
}
