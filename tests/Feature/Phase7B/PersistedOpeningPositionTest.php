<?php

namespace Tests\Feature\Phase7B;

use App\Models\FirmQuarterClose;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class PersistedOpeningPositionTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_q4_engine_input_uses_every_persisted_q3_opening_field_without_recomputation(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Q3 to Q4 persisted opening');
        $q3 = $firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        $q3Hash = $q3->payload_hash;
        $spy = new CapturingEngine;
        $this->app->instance(ManufacturingEngine::class, $spy);
        $quarters = app(QuarterManagementService::class);
        $quarters->open($course->faculty, $q4);
        app(FirmDecisionService::class)->submit($student, $firm, $q4, $this->completePayload(), 0);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->firstOrFail());

        $this->assertCount(1, $spy->inputs);
        $this->assertSame(4, $spy->inputs[0]->quarterNumber);
        $opening = $spy->inputs[0]->openingPosition;
        $fields = $q3->fields;
        $this->assertSame((float) $fields['bs_cash'], $opening->cash);
        $this->assertSame((float) $fields['bs_ar'], $opening->accountsReceivable);
        $this->assertSame((float) $fields['bs_inventory'], $opening->inventory);
        $this->assertSame((float) $fields['bs_ap'], $opening->accountsPayable);
        $this->assertSame((float) $fields['bs_curr_ltd'] + (float) $fields['bs_ltd'], $opening->debt);
        $this->assertSame((float) $fields['bs_retained'] + (float) $fields['bs_curr_ni'], $opening->retainedEarnings);
        $this->assertSame((float) $fields['bs_accum_depr'], $opening->accumulatedDepreciation);
        $this->assertSame((float) $fields['bs_ppe_gross'], $opening->plantInvestment);
        $this->assertSame((float) $fields['bs_common'], $opening->corporateCapitalization);
        $this->assertSame((float) $fields['bs_warranty_res'], $opening->warrantyReserve);
        $this->assertSame((float) $fields['bs_sl_accrual'], $opening->serviceLevelAccrual);
        $this->assertSame((float) $fields['bs_excise'], $opening->excisePayable);
        $this->assertSame((float) $fields['bs_bank_overdraft'], $opening->bankOverdraft);
        $this->assertSame((float) $fields['n4_mfg_gross'], $opening->ppeComponents->manufacturing);
        $this->assertSame((float) $fields['n4_tech_gross'], $opening->ppeComponents->technology);
        $this->assertSame((float) $fields['n4_dc_gross'], $opening->ppeComponents->distributionCenter);
        $this->assertSame($q3Hash, $q3->fresh()->payload_hash);
    }

    public function test_q4_dividend_is_preserved_in_persisted_q4_to_q5_retained_and_full_opening_chain(): void
    {
        [$course, $firm, $student, $q4, $q5] = $this->phase7bCourse('Dividend Q4 to Q5 persisted opening');
        $quarters = app(QuarterManagementService::class);
        $decisions = app(FirmDecisionService::class);
        $quarters->open($course->faculty, $q4);
        $openingQ4 = $decisions->opening($firm, $q4);
        $dividend = 1000.0;
        $decisions->submit($student, $firm, $q4, $this->completePayload(), $dividend);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->where('course_quarter_id', $q4->id)->firstOrFail());
        $quarters->finalize($q4);
        $q4Result = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
        $before = $firm->engineResults()->orderBy('quarter_number')->get()->mapWithKeys(fn ($row) => [$row->quarter_number => [$row->id, $row->payload_hash, $row->created_at->toJSON(), $row->updated_at->toJSON()]])->all();
        $netIncome = (float) $q4Result->fields['bs_curr_ni'];
        $expectedRetained = round($openingQ4->retainedEarnings + $netIncome - $dividend, 2);
        $this->assertEqualsWithDelta($expectedRetained, (float) $q4Result->fields['bs_retained'] + (float) $q4Result->fields['bs_curr_ni'], 0.001);

        $quarters->open($course->faculty, $q5);
        $spy = new CapturingEngine;
        $this->app->instance(ManufacturingEngine::class, $spy);
        $decisions->submit($student, $firm, $q5, $this->completePayload(), 0);
        $quarters->queueClose($course->faculty, $q5);
        app(FirmQuarterCloseService::class)->close(FirmQuarterClose::query()->where('course_quarter_id', $q5->id)->firstOrFail());
        $quarters->finalize($q5);

        $opening = $spy->inputs[0]->openingPosition;
        $fields = $q4Result->fields;
        $this->assertEqualsWithDelta($expectedRetained, $opening->retainedEarnings, 0.001);
        $this->assertNotSame(round($openingQ4->retainedEarnings + $netIncome, 2), $opening->retainedEarnings);
        $this->assertSame((float) $fields['bs_cash'], $opening->cash);
        $this->assertSame((float) $fields['bs_ar'], $opening->accountsReceivable);
        $this->assertSame((float) $fields['bs_inventory'], $opening->inventory);
        $this->assertSame((float) $fields['bs_ap'], $opening->accountsPayable);
        $this->assertSame((float) $fields['bs_warranty_res'], $opening->warrantyReserve);
        $this->assertSame((float) $fields['bs_sl_accrual'], $opening->serviceLevelAccrual);
        $this->assertSame((float) $fields['bs_excise'], $opening->excisePayable);
        $this->assertSame((float) $fields['bs_bank_overdraft'], $opening->bankOverdraft);
        $this->assertSame((float) $fields['bs_accum_depr'], $opening->accumulatedDepreciation);
        $this->assertSame([1, 2, 3, 4, 5], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
        foreach ($before as $quarter => $snapshot) {
            $row = $firm->engineResults()->where('quarter_number', $quarter)->firstOrFail();
            $this->assertSame($snapshot, [$row->id, $row->payload_hash, $row->created_at->toJSON(), $row->updated_at->toJSON()]);
        }
        $this->assertNotNull($firm->engineResults()->where('quarter_number', 5)->value('validated_at'));
    }
}

class CapturingEngine implements ManufacturingEngine
{
    /** @var array<int, ManufacturingEngineInput> */
    public array $inputs = [];

    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult
    {
        $this->inputs[] = $input;

        return (new ManufacturingCFEngine)->calculate($input);
    }
}
