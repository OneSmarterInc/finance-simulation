<?php

use App\Models\AccountingRequestReview;
use App\Models\AiQuestion;
use App\Models\Course;
use App\Models\CourseDebrief;
use App\Models\Faculty;
use App\Models\FirmQuarterReading;
use App\Models\ReportingCall;
use App\Services\Ai\AnthropicRequest;
use App\Services\Ai\QuarterReadings;
use App\Services\Ai\RequestReviews;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Services\Quarters\QuarterScheduler;
use App\Services\Reporting\ReportingViews;
use App\Services\Tour\TourFigures;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Walkthrough item 46: quarters close at their deadline and the next one
 * opens without anyone pressing a button. The server must run
 * `php artisan schedule:run` every minute (cron) and a queue worker.
 */
Artisan::command('flexee:quarters', function (QuarterScheduler $scheduler) {
    foreach ($scheduler->tick() as $line) {
        $this->line($line);
    }
})->purpose('Close quarters at their deadline and open the next quarter');

/*
 * U9: rehearse a course with the three reference teams, for QA and release
 * checks. Makes the course's practice run if it has none, plays it to the
 * quarter asked, and prints value created by each team.
 */
Artisan::command('flexee:practice {course : the id of the course to rehearse} {--to=11 : play to the end of this quarter} {--again : start a fresh run first}', function (PracticeRunService $runs) {
    $source = Course::query()->findOrFail($this->argument('course'));
    $faculty = $source->faculty;
    $practice = $runs->current($source);
    if ($practice && $this->option('again')) {
        $practice = $runs->startAgain($faculty, $practice);
    }
    $practice ??= $runs->create($faculty, $source);
    $practice = $runs->playTo($faculty, $practice, (int) $this->option('to'));

    $this->info("{$practice->name}: played to Quarter {$runs->playedTo($practice)}");
    $rows = [];
    foreach ($runs->valueCreated($practice) as $quarter => $value) {
        arsort($value);
        $rows[] = ["Q{$quarter}", ...array_map(fn ($team) => '$'.number_format($value[$team] / 1e6, 1).'M', ReferenceTeams::TEAMS), ReferenceTeams::NAMES[array_key_first($value)]];
    }
    $this->table(['Quarter', 'Careful', 'Average', 'Careless', 'Leads'], $rows);
    if (collect($rows)->contains(fn ($row) => end($row) !== ReferenceTeams::NAMES['careful'])) {
        $this->warn('The careful team does not lead every quarter. That is a calibration problem to report.');
    }
})->purpose('Rehearse a course with the three reference teams');

Schedule::command('flexee:quarters')->everyMinute()->withoutOverlapping(10)->onOneServer();

/*
 * U9 layer 6 (FF6): remove practice runs not played for 30 days, and any
 * copies an earlier Start again left hidden.
 */
Artisan::command('flexee:practice-tidy', function (PracticeRunService $runs) {
    $this->info('Practice runs removed: '.$runs->tidy());
})->purpose('Remove practice runs not played for 30 days');

/*
 * Live walkthrough L8: the adopter tour's figures. Plays a reference
 * Flexee Financial Accounting+ course on a throwaway copy, writes
 * resources/tour/figures.json and removes everything it made. Run in
 * development and commit the file; the tour pages only read it.
 */
Artisan::command('flexee:tour-figures {--faculty= : email of the faculty account to play it as (default: the first)}', function (TourFigures $tour) {
    $faculty = $this->option('faculty')
        ? Faculty::query()->whereHas('user', fn ($q) => $q->where('email', $this->option('faculty')))->firstOrFail()
        : Faculty::query()->orderBy('created_at')->firstOrFail();
    $figures = $tour->generate($faculty);
    TourFigures::write($figures);
    $this->info('Wrote '.TourFigures::PATH.'. Careful created $'.number_format($figures['value_created']['careful'][11] / 1e6, 1).'M by Quarter 11.');
})->purpose('Write the adopter tour figures from a reference practice run');

Schedule::command('flexee:practice-tidy')->dailyAt('03:17')->withoutOverlapping(30)->onOneServer();

/*
 * Ask Claude (U1): one tiny request to Anthropic, to check the key and that the
 * server can reach api.anthropic.com before the helper is switched on (AC8).
 */
Artisan::command('flexee:ai-check', function () {
    if (blank(config('services.anthropic.key'))) {
        $this->error('ANTHROPIC_API_KEY is not set.');

        return 1;
    }
    $post = fn (array $body) => Http::withHeaders([
        'x-api-key' => (string) config('services.anthropic.key'),
        'anthropic-version' => (string) config('services.anthropic.version'),
    ])->timeout(55)->acceptJson()->post((string) config('services.anthropic.url'), $body)->throw()->json();
    $text = fn (array $r) => trim((string) (collect($r['content'] ?? [])->firstWhere('type', 'text')['text'] ?? ''));

    try {
        $response = $post([
            'model' => config('flexee.ai.model'),
            'max_tokens' => 200,
            'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ready']],
        ]);
    } catch (Throwable $e) {
        $this->error('Anthropic could not be reached: '.$e->getMessage());

        return 1;
    }
    $this->info('Anthropic answered with '.config('flexee.ai.model').': '.$text($response));

    // The readings, reviews and captions answer through a tool, in exactly this shape.
    try {
        $response = $post([
            ...AnthropicRequest::base(),
            'system' => 'You check that tool use works. '.AnthropicRequest::only('write_check'),
            ...AnthropicRequest::answerWith('write_check', 'Store the check.', ['type' => 'object', 'properties' => ['word' => ['type' => 'string']], 'required' => ['word']]),
            'messages' => [['role' => 'user', 'content' => 'Store the word "ready".']],
        ]);
    } catch (Throwable $e) {
        $this->error('A tool-use request failed: '.$e->getMessage());

        return 1;
    }
    $use = collect($response['content'] ?? [])->first(fn ($b) => ($b['type'] ?? '') === 'tool_use' && ($b['name'] ?? '') === 'write_check');
    if (! $use) {
        $this->error('Claude answered without the tool: '.($text($response) ?: 'no text'));

        return 1;
    }
    $this->info('Tool use works ('.(config('flexee.ai.thinking') ?: 'default').' thinking): '.json_encode($use['input'] ?? []));
    $this->line(config('flexee.ai.enabled') ? 'Ask Claude is switched on (FLEXEE_AI_ENABLED=true).' : 'Ask Claude is still off. Set FLEXEE_AI_ENABLED=true to switch it on.');

    return 0;
})->purpose('Check the Anthropic key, the connection and tool use for the AI features');

/*
 * After a fault is fixed: the readings and reviews that failed three times
 * are left alone, and a debrief whose captions failed waits for new figures.
 * This clears those failures so the scheduler (or the next page view) tries again.
 */
Artisan::command('flexee:ai-retry', function () {
    $readings = FirmQuarterReading::query()->where('status', FirmQuarterReading::FAILED)->delete();
    $reviews = AccountingRequestReview::query()->where('status', AccountingRequestReview::FAILED)->delete();
    $debriefs = CourseDebrief::query()->whereNotNull('last_error')->update(['data_hash' => null, 'last_error' => null]);
    $this->info("Cleared {$readings} failed readings, {$reviews} failed request reviews and {$debriefs} debriefs whose captions failed. They're written again within a few minutes.");
})->purpose('Clear failed AI readings, reviews and captions so they are tried again');

/*
 * Live walkthrough L15 (U11 layer 2): Claude's reading of each closed
 * quarter, written by the clock and never inside the close, a few at a time,
 * for courses with "Show to me" or "Show to students" on.
 */
Artisan::command('flexee:readings {--limit= : how many to write this run}', function (QuarterReadings $readings) {
    $this->info('Readings written: '.$readings->tick($this->option('limit') !== null ? (int) $this->option('limit') : null));
})->purpose('Write Claude\'s reading of quarters that have closed');

Schedule::command('flexee:readings')->everyMinute()->withoutOverlapping(5)->onOneServer();

/*
 * U12: Claude's review of each accounting-method request, written by the
 * clock once the request is filed, for courses with the review switched on.
 */
Artisan::command('flexee:request-reviews {--limit= : how many to write this run}', function (RequestReviews $reviews) {
    $this->info('Request reviews written: '.$reviews->tick($this->option('limit') !== null ? (int) $this->option('limit') : null));
})->purpose('Write Claude\'s review of accounting-method requests waiting for faculty');

Schedule::command('flexee:request-reviews')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Ask Claude's question log is kept for 90 days (U1, AC7).
Schedule::command('model:prune', ['--model' => [AiQuestion::class]])->daily()->onOneServer();

/*
 * U3: the reporting connector's health row reads when the scheduler last ran
 * (so a stopped cron shows on the family dashboard), and, once a day, when the
 * site's certificate expires. The same daily run drops old codes and call logs.
 */
Schedule::call(fn () => Cache::forever(ReportingViews::SCHEDULER_KEY, now()->getTimestamp()))
    ->everyMinute()->name('flexee:scheduler-heartbeat')->onOneServer();

Artisan::command('flexee:reporting-tidy', function () {
    $expires = ReportingViews::checkCertificate();
    $this->info($expires ? 'Certificate expires '.date('Y-m-d', $expires).'.' : 'Certificate not checked (APP_URL isn\'t https, or the site didn\'t answer).');
    $codes = DB::table('reporting_codes')->where('expires_at', '<', now()->subDay())->delete();
    $calls = ReportingCall::query()->where('created_at', '<', now()->subDays(90))->delete();
    $this->info("Removed {$codes} old sign-in codes and {$calls} call logs over 90 days old.");
})->purpose('Check the site certificate and tidy the reporting connector\'s logs');

Schedule::command('flexee:reporting-tidy')->dailyAt('04:10')->onOneServer();
