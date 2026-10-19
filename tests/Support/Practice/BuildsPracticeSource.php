<?php

namespace Tests\Support\Practice;

use App\Models\Course;
use App\Models\Scenario;
use App\Services\Courses\CourseConfigurationService;
use App\Support\Scenario\CfManufacturingScenarioDefinition;

/** A course to rehearse, set up the way the wizard sets one up. Needs $this->faculty. */
trait BuildsPracticeSource
{
    /** @var array<string, int> when each area opens in the source course: the golden fixtures' schedule. */
    protected static array $practiceOpens = ['forecasting' => 4, 'sourcing' => 4, 'notes_elections' => 4, 'pricing' => 5, 'marketing' => 6, 'production' => 7, 'quality' => 8, 'logistics' => 8];

    /** An eleven-quarter course set up through the wizard's services, not deployed. */
    protected function sourceCourse(string $name, bool $methodChanges = false, bool $plus = false): Course
    {
        $courses = app(CourseConfigurationService::class);
        $scenario = Scenario::query()->where('code', CfManufacturingScenarioDefinition::CODE)->firstOrFail();
        $course = $courses->saveSetup($this->faculty, [
            'name' => $name, 'institution_id' => $this->faculty->institution_id, 'scenario_id' => $scenario->id,
            'starts_on' => '2027-01-01', 'ends_on' => '2029-12-31', 'payment_mode' => null,
            'cf_plus_enabled' => $plus, 'accounting_election_enabled' => $methodChanges,
            'build_quarters' => $plus ? 2 : null, 'adversarial_quarters' => $plus ? 2 : null, 'post_bid_quarters' => $plus ? 2 : null,
        ]);
        $schedule = [];
        foreach (range(1, 11) as $quarter) {
            $start = now()->setDate(2027, 1, 1)->startOfDay()->addMonths(3 * ($quarter - 1));
            $end = $start->copy()->addMonths(3)->subDay();
            $schedule[] = ['quarter_number' => $quarter, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'decision_deadline_at' => $end->toDateString()];
        }
        $courses->saveSchedule($this->faculty, $course, $schedule);
        $codes = $scenario->decisionCategories()->orderBy('sort_order')->pluck('code')->all();
        $version = $course->engineVersion();
        $matrix = [];
        foreach (range(4, 11) as $quarter) {
            foreach ($codes as $code) {
                $opens = self::$practiceOpens[$code] ?? null;
                // Version 1 courses must open production in Quarter 4.
                if ($code === 'production' && $version === 1) {
                    $opens = 4;
                }
                $matrix[$quarter][$code] = $opens !== null && $quarter >= $opens;
            }
        }
        $courses->saveEnablements($this->faculty, $course, $matrix);
        $courses->saveFacultyRoles($this->faculty, $course, []);

        return $course->fresh();
    }
}
