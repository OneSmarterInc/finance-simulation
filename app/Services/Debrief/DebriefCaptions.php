<?php

namespace App\Services\Debrief;

use App\Models\Course;
use App\Models\CourseDebrief;
use App\Services\Ai\AdviceCheck;
use App\Services\Ai\AskClaude;
use App\Services\Ai\FigureCheck;
use App\Services\Ai\QuarterReadings;
use App\Support\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * U4 (DB3): Claude words each debrief slide from the figures the engine
 * picked. One call for all slides, checked in code (figures and advice),
 * stored with a hash of the figures, and written again only when the
 * figures change. Every slide keeps the plain sentence built in code as its
 * fallback, so the debrief never waits on Claude and never needs the key.
 */
class DebriefCaptions
{
    private const PROMPT_VERSION = 'debrief-v1';

    public static function hash(array $built): string
    {
        return hash('sha256', self::PROMPT_VERSION."\n".collect($built['slides'])->map(fn ($s) => $s['key']."\n".$s['facts'])->join("\n\n"));
    }

    public static function record(Course $course): CourseDebrief
    {
        return CourseDebrief::query()->firstOrCreate(['course_id' => $course->id]);
    }

    /**
     * Each slide's sentence: Claude's when it was written from these figures and passed the checks, else the fallback.
     *
     * @return array<string, array{text: string, by: string}>
     */
    public function captions(Course $course, array $built): array
    {
        $record = CourseDebrief::query()->where('course_id', $course->id)->first();
        $fresh = $record && $record->data_hash === self::hash($built);
        $out = [];
        foreach ($built['slides'] as $key => $slide) {
            $text = $fresh ? ($record->captions[$key] ?? null) : null;
            $out[$key] = filled($text) ? ['text' => $text, 'by' => 'claude'] : ['text' => $slide['fallback'], 'by' => 'code'];
        }

        return $out;
    }

    /** Claude's captions are wanted: AI is on and the stored ones were written from other figures (or never). */
    public function due(Course $course, array $built): bool
    {
        if (! QuarterReadings::siteReady() || $built['slides'] === []) {
            return false;
        }
        $record = CourseDebrief::query()->where('course_id', $course->id)->first();
        if ($record && $record->data_hash === self::hash($built)) {
            return false;
        }

        return AskClaude::spentThisMonth() < (float) config('flexee.ai.monthly_spend_ceiling_usd');
    }

    public function write(Course $course, array $built): CourseDebrief
    {
        $record = self::record($course);
        $hash = self::hash($built);
        $facts = collect($built['slides'])->map(fn ($s) => strtoupper($s['title'])." [{$s['key']}]\n".$s['facts'])->join("\n\n");
        try {
            $response = $this->send($this->system($course, $built), $facts, array_keys($built['slides']));
            $use = collect($response['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'write_captions');
            if (! $use) {
                throw new RuntimeException('The reply held no captions.');
            }
            $captions = [];
            $dropped = [];
            foreach ($built['slides'] as $key => $slide) {
                $advice = AdviceCheck::apply(trim((string) (($use['input'] ?? [])[$key] ?? '')));
                $check = FigureCheck::apply($advice['text'], [$slide['facts']]);
                foreach ($advice['dropped'] as $s) {
                    $dropped[] = ['slide' => $key, 'sentence' => $s, 'reason' => 'advice'];
                }
                foreach ($check['dropped'] as $d) {
                    $dropped[] = ['slide' => $key, 'sentence' => $d['sentence'], 'reason' => 'figures: '.implode(', ', $d['figures'])];
                }
                $captions[$key] = trim($check['answer']) !== '' ? trim($check['answer']) : null;
            }
            $in = (int) ($response['usage']['input_tokens'] ?? 0) + (int) ($response['usage']['cache_read_input_tokens'] ?? 0) + (int) ($response['usage']['cache_creation_input_tokens'] ?? 0);
            $out = (int) ($response['usage']['output_tokens'] ?? 0);
            $record->forceFill([
                'captions' => $captions, 'dropped' => $dropped ?: null, 'data_hash' => $hash, 'model' => (string) config('flexee.ai.model'),
                'input_tokens' => $in, 'output_tokens' => $out, 'last_error' => null, 'written_at' => now(),
                'cost_usd' => round($in / 1e6 * (float) config('flexee.ai.price_input_per_mtok') + $out / 1e6 * (float) config('flexee.ai.price_output_per_mtok'), 4),
            ])->save();
        } catch (Throwable $e) {
            Log::warning('debrief_captions_failed', ['course_id' => $course->id, 'error' => $e->getMessage()]);
            // Remember the figures, so a failure isn't retried on every page view; the fallbacks stand.
            $record->forceFill(['captions' => null, 'data_hash' => $hash, 'last_error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
        }

        return $record->refresh();
    }

    private function system(Course $course, array $built): string
    {
        $product = Product::name($course);
        $sofar = $built['finished'] ? 'The course has finished.' : 'The course is still running; these are its results so far.';

        return <<<TXT
        You write the caption for each slide of a class debrief in {$product}, a course simulation in reading financial statements. Each team of students ran a manufacturing firm. The instructor shows these slides to the class. {$sofar}

        For each slide, write one or two plain sentences that say what the figures show, with the write_captions tool.
        - Use only the figures given for that slide, quoted exactly as given, for example "\$21,613,457.97". Don't add, subtract or work out new figures; describe a difference in words ("more than twice", "well above"). Any sentence with a figure that isn't given is removed.
        - Name firms as given. Don't name or judge students, and don't tell anyone what they should have done. Any sentence that gives advice is removed.
        - Short sentences, one idea each, in words a first-year student knows. No headings, bold or lists.
        TXT;
    }

    /** @param list<string> $keys @return array<string, mixed> */
    private function send(string $system, string $facts, array $keys): array
    {
        // A web request needs room for one call to Claude; the console has no limit, and setting one there would cap the whole run.
        if (! app()->runningInConsole()) {
            @set_time_limit(90);
        }
        $properties = [];
        foreach ($keys as $key) {
            $properties[$key] = ['type' => 'string', 'description' => 'The caption for the slide marked ['.$key.'].'];
        }

        return Http::withHeaders([
            'x-api-key' => (string) config('services.anthropic.key'),
            'anthropic-version' => (string) config('services.anthropic.version'),
        ])->timeout((int) config('flexee.ai.timeout_seconds', 55))->acceptJson()->post((string) config('services.anthropic.url'), [
            'model' => config('flexee.ai.model'),
            'max_tokens' => (int) config('flexee.ai.max_tokens', 1500),
            'system' => [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]],
            'tools' => [[
                'name' => 'write_captions',
                'description' => 'Store one caption per slide.',
                'input_schema' => ['type' => 'object', 'properties' => $properties, 'required' => $keys],
            ]],
            'tool_choice' => ['type' => 'tool', 'name' => 'write_captions'],
            'messages' => [['role' => 'user', 'content' => "The slides and their figures.\n\n".$facts]],
        ])->throw()->json();
    }
}
