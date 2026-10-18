<?php

namespace Tests\Feature\Economics;

use App\Models\Course;
use App\Models\CourseDecisionEnablement;
use App\Models\FirmEngineResult;
use App\Models\FirmQuarterClose;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\MarketPath;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use RuntimeException;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * A course created on engine version 2 runs end to end: the warm-up, then
 * student quarters whose demand comes from the market and whose unsold
 * stock carries into the next quarter.
 */
class EngineV2CourseTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_new_courses_stay_on_version_1_until_switched(): void
    {
        [$course, $firm] = $this->phase7bCourse('Still version 1');

        $this->assertSame(1, $course->engineVersion());
        $this->assertSame([FirmEngineResult::ENGINE_CONTRACT], $firm->engineResults()->distinct()->pluck('engine_contract')->all());
    }

    public function test_a_course_keeps_its_engine_version_once_it_has_results(): void
    {
        [$course, $firm, , $q4] = $this->phase7bCourse('Locked version');

        try {
            $course->update(['engine_version' => 2]);
            $this->fail('the engine version changed after the warm-up');
        } catch (LogicException) {
            $this->assertSame(1, $course->fresh()->engineVersion());
        }

        // Even if the column were changed behind the model's back, Quarter 4 refuses to open from v1 results.
        Course::query()->whereKey($course->id)->toBase()->update(['engine_version' => 2]);
        $this->expectException(RuntimeException::class);
        app(FirmDecisionService::class)->opening($firm->fresh('course'), $q4);
    }

    public function test_a_version_2_course_warms_up_and_closes_from_the_market(): void
    {
        config(['flexee.engine_version_for_new_courses' => 2]);
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Version 2');
        $this->assertSame(2, $course->engineVersion());

        $warmup = $firm->engineResults()->orderBy('quarter_number')->get();
        $this->assertSame([1, 2, 3], $warmup->pluck('quarter_number')->all());
        $parameters = app(FirmDecisionService::class)->parameters($firm);
        foreach ($warmup as $result) {
            $this->assertSame(FirmEngineResult::ENGINE_CONTRACT_V2, $result->engine_contract);
            $this->assertEquals(0, $result->fields['n2_short_p1'] + $result->fields['n2_short_p2'], 'the warm-up sells everything');
            $this->assertEquals(0, $result->fields['n3_units_p1'] + $result->fields['n3_units_p2']);
            $this->assertEquals(MarketPath::forFirm($firm, $result->quarter_number, $parameters), $result->intermediates['market']);
        }

        // Quarter 4: the team forecasts 40% above the market and makes it all.
        $market = MarketPath::forFirm($firm, 4, $parameters);
        $payload = $this->completePayload();
        foreach ($market as $field => $units) {
            $payload['forecasting'][$field] = (int) round($units * 1.4);
        }
        $payload['production']['p1_production'] = $payload['forecasting']['forecast_p1_r1'] + $payload['forecasting']['forecast_p1_r2'] + $payload['forecasting']['forecast_p1_r3'];
        $payload['production']['p2_production'] = $payload['forecasting']['forecast_p2_r1'] + $payload['forecasting']['forecast_p2_r2'] + $payload['forecasting']['forecast_p2_r3'];
        $payload['production']['shifts'] = 2;
        $q4Result = $this->close($course, $firm, $student, $q4, $payload);

        $this->assertSame(FirmEngineResult::ENGINE_CONTRACT_V2, $q4Result->engine_contract);
        $this->assertSame($market, $q4Result->intermediates['market']);
        $ordered = array_sum(array_map(fn ($k) => $q4Result->fields[$k], ['n2_ord_r1_p1', 'n2_ord_r2_p1', 'n2_ord_r3_p1']));
        $this->assertEquals($market['forecast_p1_r1'] + $market['forecast_p1_r2'] + $market['forecast_p1_r3'], $ordered, 'orders come from the market, not the forecast');
        $this->assertGreaterThan(0, $q4Result->fields['n3_units_p1'], 'what customers did not order is left in stock');

        // Quarter 5: production is closed, so the plant nets the stock on hand, and that stock enters COGS.
        CourseDecisionEnablement::query()->where('course_quarter_id', $q5->id)
            ->where('scenario_decision_category_id', $course->scenario->decisionCategories()->where('code', 'production')->value('id'))
            ->update(['enabled' => false]);
        $q5Payload = $this->completePayload();
        unset($q5Payload['production']);
        $q5Result = $this->close($course, $firm, $student, $q5, $q5Payload);
        $resolved = $firm->quarterDecisions()->where('course_quarter_id', $q5->id)->firstOrFail()->resolved_payload;
        $wanted = $q5Payload['forecasting']['forecast_p1_r1'] + $q5Payload['forecasting']['forecast_p1_r2'] + $q5Payload['forecasting']['forecast_p1_r3'];
        $this->assertEquals(max(0, $wanted - $q4Result->fields['n3_units_p1']), $resolved['production']['p1_production']);
        $this->assertEqualsWithDelta($q4Result->fields['n3_value_p1'] + $q4Result->fields['n3_value_p2'], $q5Result->fields['is_beg_inv'], 0.001);
        $this->assertArrayNotHasKey('certification', $resolved);
        $this->assertArrayNotHasKey('postponed_flag', $resolved['production']);
    }

    private function close($course, $firm, $student, $quarter, array $payload): FirmEngineResult
    {
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $quarter);
        app(FirmDecisionService::class)->submit($student, $firm, $quarter, $payload, 0);
        Queue::fake();
        $quarters->queueClose($course->faculty, $quarter);
        $attempt = FirmQuarterClose::query()->where('course_quarter_id', $quarter->id)->firstOrFail();
        app(FirmQuarterCloseService::class)->close($attempt);
        $this->assertSame(FirmQuarterClose::SUCCEEDED, $attempt->fresh()->status, json_encode($attempt->fresh()->safe_error));
        $quarters->finalize($quarter);

        return $firm->engineResults()->where('quarter_number', $quarter->quarter_number)->firstOrFail();
    }
}
