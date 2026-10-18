<?php

use App\Models\Course;
use App\Services\Practice\PracticeRunService;
use App\Services\Practice\ReferenceTeams;
use App\Services\Quarters\QuarterScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
